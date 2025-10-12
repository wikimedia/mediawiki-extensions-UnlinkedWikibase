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

		$luaFile = dirname( __DIR__ ) . '/scribunto/' . $this->getLuaFileName();
		return $this->getEngine()->registerInterface( $luaFile, $lib );
	}

	protected function getLuaFileName(): string {
		return 'UnlinkedWikibase.entity.lua';
	}
}
