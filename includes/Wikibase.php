<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use DateTime;
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
	private $propIds;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getMainConfig();
		$this->requestFactory = $services->getHttpRequestFactory();
		$this->cache = $services->getMainWANObjectCache();
		$this->contentLang = $services->getContentLanguage();
	}

	/**
	 * @param Parser $parser
	 * @param array $params
	 * @return array
	 */
	public function getApiResult( Parser $parser, array $params ): array {
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		if ( str_ends_with( $baseUrl, '/wiki' ) ) {
			$baseUrl = substr( $baseUrl, 0, -strlen( '/wiki' ) );
		}
		$url = $baseUrl . '/w/api.php?' . http_build_query( $params );
		$data = $this->fetch( $parser, $url, $this->cache::TTL_MINUTE );
		return $data;
	}

	/**
	 * Get data about a single Wikibase entity.
	 */
	public function getEntity( Parser $parser, string $id ): ?array {
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		$url = $baseUrl . "/Special:EntityData/$id.json";
		$data = $this->fetch( $parser, $url, $this->cache::TTL_MINUTE );
		$entities = $data['entities'] ?? [];
		return reset( $entities ) ?: null;
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
			$propDetails = $this->getApiResult( $parser, [
				'action' => 'wbsearchentities',
				'format' => 'json',
				'search' => $nameOrId,
				'language' => $this->contentLang->getCode(),
				'type' => 'property',
				'limit' => 1,
				'props' => '',
				'formatversion' => 2,
			] );
			$this->propIds[ $nameOrId ] = $propDetails['search'][0]['id'] ?? null;
		}
		return $this->propIds[ $nameOrId ];
	}

	/**
	 * Fetch data from a JSON URL.
	 */
	public function fetch( Parser $parser, string $url, int $ttl ): array {
		$requestFactory = $this->requestFactory;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $url ),
			$ttl,
			static function () use ( $url, $parser, $requestFactory ) {
				$parser->incrementExpensiveFunctionCount();
				$result = $requestFactory->request( 'GET', $url, [ 'followRedirects' => true ] );
				// Handle returned JSON.
				if ( $result === null ) {
					return [];
				}
				return json_decode( $result, true );
			}
		);
	}

	/**
	 * Format a single claim according to the conventions of Wikibase.
	 * https://www.wikidata.org/wiki/Wikidata:How_to_use_data_on_Wikimedia_projects#Parser_function
	 * @return string
	 */
	public function formatClaimAsWikitext( array $claim ): string {
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

		// External ID.
		if ( $claim['mainsnak']['datatype'] === 'external-id' ) {
			// @todo Handle formatter URL.
			return $claim['mainsnak']['datavalue']['value'];
		}
	}
}
