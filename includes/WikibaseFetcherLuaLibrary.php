<?php

namespace MediaWiki\Extension\WikibaseFetcher;

use MediaWiki\MediaWikiServices;
use Scribunto_LuaLibraryBase;

class WikibaseFetcherLuaLibrary extends Scribunto_LuaLibraryBase {

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
		$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		// @todo Make remote URL configurable.
		$url = "https://www.wikidata.org/wiki/Special:EntityData/$id.json";
		$response = $requestFactory->request( 'GET', $url );
		if ( $response ) {
			$responseData = json_decode( $response, true );
			return $responseData['entities'][$id] ?: [];
		}
		return [];
	}
}
