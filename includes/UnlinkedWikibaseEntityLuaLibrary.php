<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;

/**
 * Registers and defines functions to access UnlinkedWikibase Entity through Scribunto
 */
class UnlinkedWikibaseEntityLuaLibrary extends LibraryBase {

	/**
	 * Register the entity library.
	 *
	 * @return array Lua package
	 */
	public function register(): array {
		$lib = [];

		$luaFile = dirname( __DIR__ ) . '/scribunto/UnlinkedWikibase.entity.lua';
		return $this->getEngine()->registerInterface( $luaFile, $lib );
	}
}
