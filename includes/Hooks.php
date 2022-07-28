<?php
/**
 * @file
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\WikibaseFetcher;

/**
 * WikibaseFetcher extension hooks.
 */
class Hooks {

	/**
	 * @link https://www.mediawiki.org/wiki/Extension:Scribunto/Hooks/ScribuntoExternalLibraries
	 * @param string $engine
	 * @param string[] &$libs
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$libs ) {
		if ( $engine === 'lua' ) {
			$libs['mw.ext.wikibasefetcher'] = WikibaseFetcherLuaLibrary::class;
		}
	}
}
