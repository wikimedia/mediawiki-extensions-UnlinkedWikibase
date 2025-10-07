<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use DateTime;
use JobQueueGroup;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageFallback;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

class Wikibase {

	/** @var Config */
	private $config;

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var WANObjectCache */
	private $cache;

	/** @var Language */
	private $contentLang;

	/** @var LanguageFallback */
	private $langFallback;

	/** @var string[] */
	private $entityIds = [];

	/** @var string[] */
	private $propIds;

	/** @var JobQueueGroup */
	private JobQueueGroup $jobQueueGroup;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getMainConfig();
		$this->requestFactory = $services->getHttpRequestFactory();
		$this->contentLang = $services->getContentLanguage();
		$this->langFallback = $services->getLanguageFallback();
		$this->jobQueueGroup = $services->getJobQueueGroupFactory()->makeJobQueueGroup();

		if ( $this->config->get( "UnlinkedWikibaseCache" ) ) {
			$this->cache = $services->getObjectCacheFactory()->
				getInstance( $this->config->get( "UnlinkedWikibaseCache" ) );
		} else {
			$this->cache = $services->getMainWANObjectCache();
		}
	}

	/**
	 * @param array $params
	 * @param ?int $ttl
	 * @param ?bool $bypassCache
	 * @return array
	 */
	public function getApiResult( array $params, ?int $ttl = null, ?bool $bypassCache = false ): array {
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		if ( str_ends_with( $baseUrl, '/wiki' ) ) {
			$baseUrl = substr( $baseUrl, 0, -strlen( '/wiki' ) );
		}
		if ( $ttl === null ) {
			$ttl = $this->cache::TTL_MINUTE;
		}
		$url = $baseUrl . '/w/api.php?' . http_build_query( $params );
		return $bypassCache ? $this->fetchWithoutCache( $url ) : $this->fetch( $url, $ttl );
	}

	public function getEntityUrl( string $id ): string {
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		return $baseUrl . "/Special:EntityData/$id.json";
	}

	/**
	 * Get data about a single Wikibase entity and add a tracking page property.
	 */
	public function getEntity( Parser $parser, string $id ): ?array {
		$entity = $this->getEntityData( $id );
		// Add this ID to the list of in-use entities.
		$this->entityIds[ $id ] = $id;
		$parser->getOutput()->setPageProperty( Hooks::PAGE_PROP_ENTITIES_USED_PREFIX . count( $this->entityIds ), $id );
		return $entity;
	}

	/**
	 * Get data about a single Wikibase entity.
	 */
	public function getEntityData( string $id ): ?array {
		$url = $this->getEntityUrl( $id );
		$ttl = $this->config->get( 'UnlinkedWikibaseEntityTTL' );
		if ( $ttl === null ) {
			$ttl = $this->cache::TTL_INDEFINITE;
		}
		$data = $this->fetch( $url, $ttl );
		$entities = $data['entities'] ?? [];
		$entity = reset( $entities ) ?: null;
		if ( $entity ) {
			$id = $entity['id'];
		}
		return $entity;
	}

	/**
	 * Return [ label|null, lang|null ] for an entity ID, using the page's target language
	 * and fallback(s).
	 *
	 * @param Parser $parser
	 * @param string $id
	 * @return array{0:?string,1:?string}
	 */
	public function getLabel( Parser $parser, string $id ): array {
		$entity = $this->getEntity( $parser, $id );
		if ( !$entity ) {
			return [ null, null ];
		}

		$labels = $entity['labels'] ?? [];
		if ( !$labels ) {
			return [ null, null ];
		}

		$targetLang = $parser->getTargetLanguage()->getCode();
		$langs = array_unique( [
			$targetLang,
			...$this->langFallback->getAll( $targetLang ),
		] );
		foreach ( $langs as $lang ) {
			if ( isset( $entity['labels'][$lang]['value'] ) ) {
				return [ $entity['labels'][$lang]['value'], $lang ];
			}
		}

		return [ null, null ];
	}

	/**
	 * Return the label string for a specific language, or null if absent/invalid.
	 *
	 * @param Parser $parser
	 * @param string $id
	 * @param string $languageCode
	 * @return ?string
	 */
	public function getLabelByLanguage( Parser $parser, string $id, string $languageCode ): ?string {
		$entity = $this->getEntity( $parser, $id );
		if ( !$entity ) {
			return null;
		}

		$labels = $entity['labels'] ?? [];
		if ( !$labels ) {
			return null;
		}

		if ( isset( $labels[$languageCode]['value'] ) && is_string( $labels[$languageCode]['value'] ) ) {
			return $labels[$languageCode]['value'];
		}

		return null;
	}

	/**
	 * Return [ description|null, lang|null ] for an entity ID, using the page
	 * target language (then site content language, then configured fallbacks, then any).
	 *
	 * @param Parser $parser
	 * @param string $id
	 * @return array{0:?string,1:?string}
	 */
	public function getDescription( Parser $parser, string $id ): array {
		$entity = $this->getEntity( $parser, $id );
		if ( !$entity ) {
			return [ null, null ];
		}

		$descriptions = $entity['descriptions'] ?? [];
		if ( !$descriptions ) {
			return [ null, null ];
		}

		$targetLang = $parser->getTargetLanguage()->getCode();
		$langs = array_unique( [
			$targetLang,
			...$this->langFallback->getAll( $targetLang ),
		] );
		foreach ( $langs as $lang ) {
			if ( isset( $entity['descriptions'][$lang]['value'] ) ) {
				return [ $entity['descriptions'][$lang]['value'], $lang ];
			}
		}

		return [ null, null ];
	}

	/**
	 * Return the description string for a specific language, or null if absent/invalid.
	 *
	 * @param Parser $parser
	 * @param string $id
	 * @param string $languageCode
	 * @return ?string
	 */
	public function getDescriptionByLanguage( Parser $parser, string $id, string $languageCode ): ?string {
		$entity = $this->getEntity( $parser, $id );
		if ( !$entity ) {
			return null;
		}

		$descriptions = $entity['descriptions'] ?? [];
		if ( !$descriptions ) {
			return null;
		}

		if ( isset( $descriptions[$languageCode]['value'] ) && is_string( $descriptions[$languageCode]['value'] ) ) {
			return $descriptions[$languageCode]['value'];
		}

		return null;
	}

	/**
	 * Returns the title of the corresponding page on the local wiki or nil if it doesn't exist.
	 * When globalSiteId is given, the page title on the specified wiki is returned, rather
	 * than the one on the local wiki.
	 *
	 * @param Parser $parser
	 * @param string $itemId
	 * @param string|null $globalSiteId Uses current site ID if null.
	 * @return ?string The sitelink title, or null if not present/invalid
	 */
	public function getSiteLinkPageName( Parser $parser, string $itemId, ?string $globalSiteId ): ?string {
		$entity = $this->getEntity( $parser, $itemId );
		if ( !$entity ) {
			return null;
		}
		$siteId = $globalSiteId ?: WikiMap::getCurrentWikiId();
		$sitelinks = $entity['sitelinks'] ?? [];
		if ( !$sitelinks ) {
			return null;
		}

		if ( isset( $sitelinks[$siteId]['title'] ) && is_string( $sitelinks[$siteId]['title'] ) ) {
			return $sitelinks[$siteId]['title'];
		}

		return $sitelinks[strtolower( $siteId )]['title'] ?? null;
	}

	/**
	 * Returns a list of all badges assigned to a site link.
	 * When globalSiteId is given, the badges for the site link to the specified
	 * wiki are returned. This defaults to the local wiki.
	 *
	 * @param Parser $parser
	 * @param string $itemId
	 * @param string|null $globalSiteId Uses current site ID if null.
	 * @return string[] List of badge item IDs, empty if none
	 */
	public function getBadges( Parser $parser, string $itemId, ?string $globalSiteId ): array {
		$entity = $this->getEntity( $parser, $itemId );
		if ( !$entity ) {
			return [];
		}

		$siteId = $globalSiteId ?: WikiMap::getCurrentWikiId();
		return $entity['sitelinks'][$siteId]['badges'] ?? [];
	}

	/**
	 * Get a Wikibase property ID given a property label or ID (obviously in the case of passing in an ID, this is
	 * trivial, but we let the user provide either and this is where that's sorted out).
	 */
	public function getPropertyId( Parser $parser, string $nameOrId ): ?string {
		if ( isset( $this->propIds[ $nameOrId ] ) ) {
			return $this->propIds[ $nameOrId ];
		}
		if ( preg_match( '/P[0-9]+/', trim( $nameOrId ) ) ) {
			$this->propIds[ $nameOrId ] = trim( $nameOrId );
		} else {
			$propDetails = $this->getApiResult( [
				'action' => 'wbsearchentities',
				'format' => 'json',
				'search' => $nameOrId,
				'language' => $this->contentLang->getCode(),
				'type' => 'property',
				'limit' => 1,
				'props' => '',
				'formatversion' => 2,
			], $this->cache::TTL_WEEK );
			$this->propIds[ $nameOrId ] = $propDetails['search'][0]['id'] ?? null;
		}
		return $this->propIds[ $nameOrId ];
	}

	/**
	 * Fetch data from a JSON URL.
	 */
	public function fetch( string $url, int $ttl ): array {
		$wb = $this;
		$jobQueueGroup = $this->jobQueueGroup;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $url ),
			$ttl,
			static function ( $oldValue, &$ttl, array &$setOpts, $oldAsOf ) use ( $wb, $jobQueueGroup, $url ) {
				// If the cache doesn't support having the fetch happen in the job queue, fetch the data immediately.
				if ( !$wb->canCache() ) {
					return $wb->fetchWithoutCache( $url );
				}
				// If it's not cached, create a job that will cache it.
				$job = new JobSpecification( FetchJob::JOB_NAME, [ 'url' => $url, 'ttl' => $ttl ] );
				$jobQueueGroup->lazyPush( $job );
				// Return the old value if possible.
				return $oldValue ?: [];
			},
			// staleTTL also defined in FetchJob.
			[ 'staleTTL' => BagOStuff::TTL_WEEK ]
		);
	}

	/**
	 * Get the current UnlinkedWikibase job queue size.
	 */
	public function getJobQueueSize(): int {
		return $this->jobQueueGroup->getQueueSizes()[ FetchJob::JOB_NAME ] ?? 0;
	}

	/**
	 * Is the cache able to store data from the job queue?
	 */
	public function canCache(): bool {
		return $this->cache->getQoS( BagOStuff::ATTR_DURABILITY ) >= BagOStuff::QOS_DURABILITY_SERVICE;
	}

	public function fetchWithoutCache( string $url ): array {
		$result = $this->requestFactory->request( 'GET', $url, [ 'followRedirects' => true ], __METHOD__ );
		// Handle returned JSON.
		if ( $result === null ) {
			return [];
		}
		$data = json_decode( $result, true );
		if ( $data === null ) {
			return [];
		}
		return $data;
	}

	/**
	 * Format a single claim according to the conventions of Wikibase.
	 * https://www.wikidata.org/wiki/Wikidata:How_to_use_data_on_Wikimedia_projects#Parser_function
	 * @return ?string
	 */
	public function formatClaimAsWikitext( Parser $parser, array $claim ): ?string {
		// Commons image
		if ( $claim['mainsnak']['datatype'] === 'commonsMedia' ) {
			return '[[File:' . $claim['mainsnak']['datavalue']['value'] . '|thumb]]';
		}

		// Coordinates
		if ( $claim['mainsnak']['datatype'] === 'globe-coordinate' ) {
			$lat = $claim['mainsnak']['datavalue']['value']['latitude'];
			$lon = $claim['mainsnak']['datavalue']['value']['longitude'];
			// @todo Link to map.
			return "📍 $lat, $lon";
		}

		// Monolingual text
		if ( $claim['mainsnak']['datatype'] === 'monolingualtext' ) {
			return $claim['mainsnak']['datavalue']['value']['text'];
		}

		// Date
		if ( $claim['mainsnak']['datatype'] === 'time' ) {
			$datetime = new DateTime( $claim['mainsnak']['datavalue']['value']['time'] );
			// @todo Handle precision.
			return $datetime->format( 'j M Y' );
		}

		// Link
		if ( $claim['mainsnak']['datatype'] === 'url' ) {
			return $claim['mainsnak']['datavalue']['value'];
		}

		// External ID
		if ( $claim['mainsnak']['datatype'] === 'external-id' ) {
			$propId = $claim['mainsnak']['property'];
			$propData = $this->getEntity( $parser, $propId );
			$formatterUrlProp = $this->config->get( 'UnlinkedWikibaseFormatterUrlProp' );
			if ( isset( $propData['claims'][$formatterUrlProp][0]['mainsnak']['datavalue']['value'] ) ) {
				$urlFormat = $propData['claims'][$formatterUrlProp][0]['mainsnak']['datavalue']['value'];
				$url = str_replace( '$1', $claim['mainsnak']['datavalue']['value'], $urlFormat );
				return '[' . $url . ' ' . $claim['mainsnak']['datavalue']['value'] . ']';
			} else {
				return $claim['mainsnak']['datavalue']['value'];
			}
		}

		// Item
		if ( $claim['mainsnak']['datatype'] === 'wikibase-item' ) {
			$id = $claim['mainsnak']['datavalue']['value']['id'];
			$entity = $this->getEntity( $parser, $id );
			return $entity['labels'][$this->contentLang->getCode()]['value']
				?? $entity['labels']['en']['value']
				?? null;
		}

		// Quantity
		if ( $claim['mainsnak']['datatype'] === 'quantity' ) {
			$unitId = substr(
				$claim['mainsnak']['datavalue']['value']['unit'],
				strlen( 'http://www.wikidata.org/entity/' )
			);
			$unit = $this->getEntity( $parser, $unitId );
			$unitLabel = '';
			if ( $unit ) {
				$unitLabel = $unit['labels'][$this->contentLang->getCode()]['value']
					?? $unit['labels']['en']['value']
					?? '';
			}
			$amount = (float)$claim['mainsnak']['datavalue']['value']['amount'];
			return trim( $amount . ' ' . $unitLabel );
		}

		return null;
	}
}
