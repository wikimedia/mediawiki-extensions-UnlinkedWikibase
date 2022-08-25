<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

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

	/** @var mixed[] Runtime cache of fetched entities. */
	private $entities;

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
		if ( !preg_match( '/Q[0-9]+/', $id ) ) {
			return [ 'error' => 'invalid-item-id' ];
		}
		return [ 'result' => $this->fetch( $id ) ];
	}

	/**
	 * Fetch data from Wikibase.
	 *
	 * @param string $id Wikibase ID.
	 * @return mixed[]
	 */
	protected function fetch( $id ) {
		if ( isset( $this->entities[$id] ) ) {
			return $this->entities[$id];
		}
		$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
		$url = $baseUrl . "/Special:EntityData/$id.json";
		$this->getParser()->incrementExpensiveFunctionCount();
		$response = $this->requestFactory->request( 'GET', $url );
		if ( $response ) {
			$responseData = json_decode( $response, true );
			$this->entities[$id] = $responseData['entities'][$id] ?: null;
			return $this->entities[$id];
		}
		return [];
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
		$parser = $this->getParser();
		$requestFactory = $this->requestFactory;
		// Get cached result or send query to the remote query service.
		$out = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $url ),
			$this->config->get( 'UnlinkedWikibaseQueryTTL' ),
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
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );
		$where = [
			'pp_value' => $id,
			'pp_propname' => 'unlinkedwikibase_id'
		];
		$options = [ 'limit' => 1 ];
		$this->getParser()->incrementExpensiveFunctionCount();
		$pageId = $dbr->selectField( 'page_props', 'pp_page', $where, __METHOD__, $options );
		return [ 'result' => (int)$pageId ];
	}
}
