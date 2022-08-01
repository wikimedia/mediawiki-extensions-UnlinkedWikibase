<?php

namespace MediaWiki\Extension\WikibaseFetcher;

use MediaWiki\MediaWikiServices;
use Scribunto_LuaLibraryBase;

class WikibaseFetcherLuaLibrary extends Scribunto_LuaLibraryBase {

	/** @var mixed[] Runtime cache of fetched entities. */
	private $entities;

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
			'getLocalTitle' => [ $this, 'getLocalTitle' ],
		];
		$luaFile = dirname( __DIR__ ) . '/scribunto/wikibasefetcher.lua';
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
		$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		// @todo Make remote URL configurable.
		$url = "https://www.wikidata.org/wiki/Special:EntityData/$id.json";
		$response = $requestFactory->request( 'GET', $url );
		if ( $response ) {
			$responseData = json_decode( $response, true );
			$this->entities[$id] = $responseData['entities'][$id] ?: null;
			return $this->entities[$id];
		}
		return [];
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
			'pp_propname' => 'wikibase_fetcher_id'
		];
		$options = [ 'limit' => 1 ];
		$pageId = $dbr->selectField( 'page_props', 'pp_page', $where, __METHOD__, $options );
		return [ 'result' => (int)$pageId ];
	}
}
