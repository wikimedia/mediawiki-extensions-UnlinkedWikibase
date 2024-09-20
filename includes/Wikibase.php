<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use DateTime;
use JobQueueGroup;
use JobSpecification;
use Language;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use Parser;
use WANObjectCache;

class Wikibase {

	/** @var Config */
	private $config;

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var WANObjectCache */
	private $cache;

	/** @var Language */
	private $contentLang;

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
		$this->cache = $services->getMainWANObjectCache();
		$this->contentLang = $services->getContentLanguage();
		$this->jobQueueGroup = $services->getJobQueueGroupFactory()->makeJobQueueGroup();
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
	 * Get data about a single Wikibase entity.
	 */
	public function getEntity( Parser $parser, string $id ): ?array {
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
		// Add this ID to the list of in-use entities.
		$this->entityIds[ $id ] = $id;
		$parser->getOutput()->setPageProperty( Hooks::PAGE_PROP_ENTITIES_USED_PREFIX . count( $this->entityIds ), $id );
		return $entity;
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
		$data = $this->cache->get( $this->cache->makeKey( 'ext-UnlinkedWikibase', $url ) );
		if ( $data ) {
			return $data;
		}
		// If it's not cached, create a job that will cache it.
		$this->jobQueueGroup->lazyPush( new JobSpecification( FetchJob::JOB_NAME, [ 'url' => $url, 'ttl' => $ttl ] ) );
		return [];
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
			// @todo Handle formatter URL.
			return $claim['mainsnak']['datavalue']['value'];
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
