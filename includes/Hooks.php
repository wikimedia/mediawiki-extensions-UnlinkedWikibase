<?php
/**
 * @file
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\UnlinkedWikibase;

use Config;
use Html;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use Parser;
use Wikimedia\ParamValidator\TypeDef\BooleanDef;
use Wikimedia\Rdbms\LBFactory;

/**
 * UnlinkedWikibase extension hooks.
 */
class Hooks implements ParserFirstCallInitHook, InfoActionHook {

	public const PAGE_PROP_ID = 'unlinkedwikibase_id';

	public const PAGE_PROP_ENTITIES_USED_PREFIX = 'unlinkedwikibase_entities_used_';

	private Config $config;

	private LBFactory $connectionProvider;

	public function __construct( Config $mainConfig, LBFactory $connectionProvider ) {
		$this->config = $mainConfig;
		$this->connectionProvider = $connectionProvider;
	}

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
		$parser->setFunctionHook( 'unlinkedwikibase', [ $this, 'renderMainParserFunction' ] );
		if ( $this->config->get( 'UnlinkedWikibaseStatementsParserFunc' ) ) {
			$parser->setFunctionHook( 'statements', [ $this, 'renderStatements' ] );
		}
		return true;
	}

	/**
	 * @param Parser $parser
	 * @return mixed
	 */
	public function renderStatements( Parser $parser ) {
		$params = $this->getParserFunctionArgs( func_get_args() );
		if ( !isset( $params[0] ) ) {
			return $this->getError( 'unlinkedwikibase-error-missing-property' );
		}
		$entityId = !empty( $params['from'] )
			? $params['from']
			: $parser->getOutput()->getPageProperty( 'unlinkedwikibase_id' );
		if ( !$entityId ) {
			return $this->getError( 'unlinkedwikibase-error-statements-entity-not-set' );
		}

		$wikibase = new Wikibase();
		$propName = $params[0] ?? '';
		$propId = $wikibase->getPropertyId( $parser, $propName );
		if ( !$propId ) {
			return $this->getError( 'unlinkedwikibase-error-property-name-not-found', [ $propName ] );
		}

		$entity = $wikibase->getEntity( $parser, $entityId );
		if ( !isset( $entity['claims'][$propId] ) ) {
			// No claim for this property.
			return "<!-- No $propName ($propId) property found for $entityId -->";
		}
		$vals = [];
		// Remove deprecated claims.
		$claims = array_filter( $entity['claims'][$propId], static function ( $claim ) {
			return $claim['rank'] !== 'deprecated';
		} );
		// Include only preferred claims if there are any.
		$preferred = array_filter( $claims, static function ( $claim ) {
			return $claim['rank'] === 'preferred';
		} );
		if ( count( $preferred ) > 0 ) {
			$claims = $preferred;
		}
		foreach ( $claims as $claim ) {
			$vals[] = $wikibase->formatClaimAsWikitext( $parser, $claim );
		}
		$out = $parser->getContentLanguage()->listToText( array_filter( $vals ) );
		return Html::rawElement( 'span', [ 'class' => 'ext-UnlinkedWikibase-statements' ], $out );
	}

	/**
	 * @param mixed[] $args
	 * @return string[]
	 */
	private function getParserFunctionArgs( array $args ) {
		$params = [];
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
		return $params;
	}

	/**
	 * Render the output of the parser function.
	 * The input parameters are wikitext with templates expanded.
	 * The output should be wikitext too.
	 * @param Parser $parser The parser.
	 * @return string|mixed[] The wikitext with which to replace the parser function call.
	 */
	public function renderMainParserFunction( Parser $parser ) {
		$params = $this->getParserFunctionArgs( func_get_args() );
		if ( !isset( $params['id'] ) ) {
			return $this->getError( 'unlinkedwikibase-error-missing-id' );
		}
		if ( !preg_match( '/^Q[0-9]+$/', $params['id'] ) ) {
			return $this->getError( 'unlinkedwikibase-error-invalid-id', [ $params['id'] ] );
		}
		$parser->getOutput()->setPageProperty( self::PAGE_PROP_ID, $params['id'] );
		return '';
	}

	/**
	 * Get an error message response.
	 * @param string $msg The i18n message name.
	 * @param string[] $params The parameters to use in the message.
	 * @return mixed[] The parser function response with the error message HTML.
	 */
	private function getError( string $msg, array $params = [] ) {
		$label = wfMessage( 'unlinkedwikibase-error-label' )->escaped();
		$labelHtml = Html::rawElement( 'strong', [], $label );
		$err = wfMessage( $msg, $params )->escaped();
		$out = Html::rawElement( 'span', [ 'class' => 'error' ], "$labelHtml $err" );
		return [ 0 => $out, 'isHTML' => true ];
	}

	/**
	 * Add the saved Wikibase ID to the page info table.
	 *
	 * {@inheritDoc}
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		// Get this page's Wikibase ID.
		$props = MediaWikiServices::getInstance()
			->getPageProps()
			->getProperties( $context->getTitle(), self::PAGE_PROP_ID );
		if ( !$props ) {
			return true;
		}
		$entityId = array_shift( $props );

		// Count the number of times it's used.
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$countUsed = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'page_props' )
			->where( [
				'pp_propname' . $dbr->buildLike( self::PAGE_PROP_ENTITIES_USED_PREFIX, $dbr->anyString() ),
				'pp_value' => $entityId,
			] )
			->caller( __METHOD__ )
			->fetchField();

		// Add a row to the info table.
		$usage = $context->msg( 'unlinkedwikibase-infoaction-other-usage', $countUsed );
		$pageInfo['header-basic'][] = [
			wfMessage( 'unlinkedwikibase-infoaction-label' ),
			$entityId . ' ' . $usage
		];
		return true;
	}
}
