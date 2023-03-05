<?php
/**
 * @file
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\UnlinkedWikibase;

use Html;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use Parser;
use Wikimedia\ParamValidator\TypeDef\BooleanDef;

/**
 * UnlinkedWikibase extension hooks.
 */
class Hooks implements ParserFirstCallInitHook, InfoActionHook {

	/**
	 * @link https://www.mediawiki.org/wiki/Extension:Scribunto/Hooks/ScribuntoExternalLibraries
	 * @param string $engine
	 * @param string[] &$libs
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$libs ) {
		if ( $engine === 'lua' ) {
			$libs['mw.ext.unlinkedwikibase'] = UnlinkedWikibaseLuaLibrary::class;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'unlinkedwikibase', [ $this, 'renderParserFunction' ] );
		return true;
	}

	/**
	 * Render the output of the parser function.
	 * The input parameters are wikitext with templates expanded.
	 * The output should be wikitext too.
	 * @param Parser $parser The parser.
	 * @return string|mixed[] The wikitext with which to replace the parser function call.
	 */
	public function renderParserFunction( Parser $parser ) {
		$params = [];
		$args = func_get_args();
		// Remove $parser from the args.
		array_shift( $args );
		foreach ( $args as $arg ) {
			$pair = explode( '=', $arg, 2 );
			if ( count( $pair ) == 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				if ( in_array( $value, BooleanDef::$TRUEVALS, true ) ) {
					$value = true;
				}
				if ( in_array( $value, BooleanDef::$FALSEVALS, true ) ) {
					$value = false;
				}
				if ( $value !== '' ) {
					$params[$name] = $value;
				}
			} else {
				$params[] = $arg;
			}
		}
		if ( !isset( $params['id'] ) ) {
			return $this->getError( 'unlinkedwikibase-error-missing-id' );
		}
		if ( !preg_match( '/^Q[0-9]+$/', $params['id'] ) ) {
			return $this->getError( 'unlinkedwikibase-error-invalid-id', [ $params['id'] ] );
		}
		$parser->getOutput()->setPageProperty( 'unlinkedwikibase_id', $params['id'] );
		return '';
	}

	/**
	 * Get an error message response.
	 * @param string $msg The i18n message name.
	 * @param string[] $params The parameters to use in the message.
	 * @return mixed[] The parser function response with the error message HTML.
	 */
	private function getError( string $msg, array $params = [] ) {
		$label = wfMessage( 'unlinkedwikibase-error-label' )->text();
		$labelHtml = Html::element( 'strong', [], $label );
		$err = wfMessage( $msg, $params )->text();
		$out = Html::rawElement( 'span', [ 'class' => 'error' ], "$labelHtml $err" );
		return [ 0 => $out, 'isHTML' => true ];
	}

	/**
	 * Add the saved Wikibase ID to the page info table.
	 *
	 * {@inheritDoc}
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		$props = MediaWikiServices::getInstance()
			->getPageProps()
			->getProperties( $context->getTitle(), 'unlinkedwikibase_id' );
		if ( !$props ) {
			return true;
		}
		$pageInfo['header-basic'][] = [
			wfMessage( 'unlinkedwikibase-infoaction-label' ),
			array_shift( $props )
		];
		return true;
	}
}
