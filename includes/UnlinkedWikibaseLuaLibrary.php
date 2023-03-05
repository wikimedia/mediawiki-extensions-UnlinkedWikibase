<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use Scribunto_LuaEngine;
use Scribunto_LuaLibraryBase;
use WANObjectCache;

class UnlinkedWikibaseLuaLibrary extends Scribunto_LuaLibraryBase {

	/** @var Config */
	private $config;

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var WANObjectCache */
	private $cache;

	/**
	 * @param Scribunto_LuaEngine $engine
	 */
	public function __construct( Scribunto_LuaEngine $engine ) {
		parent::__construct( $engine );
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getMainConfig();
		$this->requestFactory = $services->getHttpRequestFactory();
		$this->cache = $services->getMainWANObjectCache();
	}

	/**
	 * Called to register the library.
	 *
	 * This should do any necessary setup and then call $this->getEngine()->registerInterface().
	 * The value returned by that call should be returned from this function,
	 * and must be for 'deferLoad' libraries to work right.
	 *
	 * @return array Lua package
	 */
	public function register(): array {
		$interfaceFuncs = [
			'getEntity' => [ $this, 'getEntity' ],
			'getLocalPageId' => [ $this, 'getLocalPageId' ],
			'query' => [ $this, 'query' ],
		];
		$luaFile = dirname( __DIR__ ) . '/scribunto/UnlinkedWikibase.lua';
		return $this->getEngine()->registerInterface( $luaFile, $interfaceFuncs );
	}

	/**
	 * Get data for a given entity.
	 *
	 * @param string $id A Wikibase item ID.
	 * @return mixed[] A result array with 'result' or 'error' key.
	 */
	public function getEntity( $id ): array {
		if ( !preg_match( '/[QPL][0-9]+/', $id ) ) {
			return [ 'error' => 'invalid-item-id' ];
		}
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		$url = $baseUrl . "/Special:EntityData/$id.json";
		$data = $this->fetch( $url, $this->cache::TTL_MINUTE );
		return [ 'result' => $data['entities'][$id] ?? [] ];
	}

	/**
	 * Fetch JSON data from a URL.
	 *
	 * @param string $url URl to fetch.
	 * @param int $ttl Cache lifetime in seconds.
	 * @return mixed[]
	 */
	private function fetch( string $url, int $ttl ) {
		$parser = $this->getParser();
		$requestFactory = $this->requestFactory;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $url ),
			$ttl,
			static function () use ( $url, $parser, $requestFactory ) {
				$parser->incrementExpensiveFunctionCount();
				$result = $requestFactory->request( 'GET', $url );
				// Handle returned JSON.
				if ( $result === null ) {
					return [];
				}
				return json_decode( $result, true );
			}
		);
	}

	/**
	 * Fetch data from a Wikibase query service.
	 *
	 * @param ?string $query The Sparql query to execute.
	 * @return mixed[]
	 */
	public function query( ?string $query ) {
		// Allow not having a query, to be more forgiving to wiki module code.
		if ( !$query ) {
			return [];
		}
		// Fetch the data.
		$serviceUrl = $this->config->get( 'UnlinkedWikibaseBaseQueryEndpoint' );
		$url = $serviceUrl . '?format=json&query=' . wfUrlencode( $query );
		$out = $this->fetch( $url, $this->config->get( 'UnlinkedWikibaseQueryTTL' ) );
		// Reformat the response for Scribunto.
		return [ 'result' => $out ];
	}

	/**
	 * Get a page ID that is linked to the given entity.
	 *
	 * @param string $id Wikibase entity ID.
	 * @return array
	 */
	public function getLocalPageId( string $id ) {
		$parser = $this->getParser();
		$method = __METHOD__;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $method, $id ),
			WANObjectCache::TTL_MINUTE * 5,
			static function () use ( $id, $parser, $method ) {
				$dbr = MediaWikiServices::getInstance()
					->getDBLoadBalancer()
					->getConnection( DB_REPLICA );
				$where = [
					'pp_value' => $id,
					'pp_propname' => 'unlinkedwikibase_id'
				];
				$options = [ 'limit' => 1 ];
				$parser->incrementExpensiveFunctionCount();
				$pageId = $dbr->selectField( 'page_props', 'pp_page', $where, $method, $options );
				return [ 'result' => (int)$pageId ];
			}
		);
	}
}
