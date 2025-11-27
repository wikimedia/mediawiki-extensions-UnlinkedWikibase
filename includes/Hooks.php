<?php
/**
 * @file
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\UnlinkedWikibase;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\OutputPageParserOutputHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Language\LanguageCode;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use Wikimedia\Rdbms\LBFactory;

/**
 * UnlinkedWikibase extension hooks.
 */
class Hooks implements
	ParserFirstCallInitHook,
	InfoActionHook,
	SidebarBeforeOutputHook,
	OutputPageParserOutputHook,
	ScribuntoExternalLibrariesHook
{

	public const PAGE_PROP_ID = 'unlinkedwikibase_id';

	public const PAGE_PROP_ENTITIES_USED_PREFIX = 'unlinkedwikibase_entities_used_';

	private const LANG_LINKS = 'unlinkedwikibase_lang_links';

	private const TRUEVALS = [ 'true', 't', 'yes', 'y', 'on', '1' ];
	private const FALSEVALS = [ 'false', 'f', 'no', 'n', 'off', '0' ];

	private Config $config;

	private LBFactory $connectionProvider;

	public function __construct( Config $mainConfig, LBFactory $connectionProvider ) {
		$this->config = $mainConfig;
		$this->connectionProvider = $connectionProvider;
	}

	/** @inheritDoc */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ) {
		if ( $engine === 'lua' ) {
			$extraLibraries['mw.ext.unlinkedwikibase'] = UnlinkedWikibaseLuaLibrary::class;
			$extraLibraries['mw.ext.unlinkedwikibase.entity'] = UnlinkedWikibaseEntityLuaLibrary::class;
			if ( $this->config->get( 'UnlinkedWikibaseImitationMode' ) ) {
				// In imitation mode, also add the alias of 'mw.wikibase'.
				$extraLibraries['mw.wikibase'] = UnlinkedWikibaseImitationLuaLibrary::class;
				$extraLibraries['mw.wikibase.entity'] = UnlinkedWikibaseEntityImitationLuaLibrary::class;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'unlinkedwikibase', [ $this, 'renderMainParserFunction' ] );
		$enableImitation = $this->config->get( 'UnlinkedWikibaseImitationMode' );
		$statementsParserFunc = $this->config->get( 'UnlinkedWikibaseStatementsParserFunc' );

		if ( $statementsParserFunc ) {
			wfDeprecatedMsg(
				'$wgUnlinkedWikibaseStatementsParserFunc is deprecated, use $wgUnlinkedWikibaseImitationMode instead'
			);
		}

		if ( $enableImitation || $statementsParserFunc ) {
			$parser->setFunctionHook( 'statements', [ $this, 'renderStatements' ] );
			$parser->setFunctionHook( 'property', [ $this, 'renderProperty' ] );
		}
		return true;
	}

	/**
	 * @param Parser $parser
	 * @return mixed
	 */
	public function renderStatements( Parser $parser ) {
		return $this->renderPropertyClaims( $parser, func_get_args(), false );
	}

	/**
	 * Render the {{#property:}} parser function.
	 *
	 * @param Parser $parser
	 * @return mixed
	 */
	public function renderProperty( Parser $parser ) {
		return $this->renderPropertyClaims( $parser, func_get_args(), true );
	}

	/**
	 * @param Parser $parser
	 * @param array $args Function arguments including parser
	 * @param bool $rawValues If true, return raw values; if false, return formatted values
	 * @return mixed
	 */
	private function renderPropertyClaims( Parser $parser, array $args, bool $rawValues ) {
		$params = $this->getParserFunctionArgs( $args );
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
			if ( $rawValues ) {
				$value = $this->getRawClaimValue( $claim );
				if ( $value !== null ) {
					$vals[] = $value;
				}
			} else {
				$vals[] = $wikibase->renderSnak( $parser, $claim['mainsnak'] );
			}
		}

		$out = $parser->getContentLanguage()->listToText( array_filter( $vals ) );
		$cssClass = $rawValues ? 'ext-UnlinkedWikibase-property' : 'ext-UnlinkedWikibase-statements';
		return Html::rawElement( 'span', [ 'class' => $cssClass ], $out );
	}

	/**
	 * Extract raw value from a claim without formatting (for #property).
	 *
	 * @param array $claim
	 * @return string|null
	 */
	private function getRawClaimValue( array $claim ): ?string {
		if ( !isset( $claim['mainsnak']['datavalue']['value'] ) ) {
			return null;
		}

		$value = $claim['mainsnak']['datavalue']['value'];
		$datatype = $claim['mainsnak']['datatype'] ?? '';

		switch ( $datatype ) {
			case 'wikibase-item':
				return $value['id'] ?? null;

			case 'monolingualtext':
				return $value['text'] ?? null;

			case 'quantity':
				return isset( $value['amount'] ) ? ltrim( $value['amount'], '+' ) : null;

			case 'time':
				return $value['time'] ?? null;

			case 'globe-coordinate':
				if ( isset( $value['latitude'] ) && isset( $value['longitude'] ) ) {
					return $value['latitude'] . ',' . $value['longitude'];
				}
				return null;

			default:
				return $value;
		}
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
				if ( in_array( $value, self::TRUEVALS, true ) ) {
					$value = true;
				}
				if ( in_array( $value, self::FALSEVALS, true ) ) {
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

		if (
			$this->config->get( 'UnlinkedWikibaseSitelinkSuffix' ) &&
			!$this->config->get( MainConfigNames::HideInterlanguageLinks ) &&
			$parser->getOutput()->getExtensionData( self::LANG_LINKS ) === null
		) {
			$wb = new Wikibase;
			$entity = $wb->getEntity( $parser, $params['id'] );
			$langLinks = $this->generateLangLinks( $entity, $parser->getTargetLanguage() );
			$parser->getOutput()->setExtensionData( self::LANG_LINKS, $langLinks );
		}
		return '';
	}

	/**
	 * Convert entity sitelinks into the form MW skin system expects
	 *
	 * @param ?array $entity Entity data
	 * @param Language $lang Language object for translating names
	 * @return array List of additional language links to add. See Skin::getLanguage()
	 */
	private function generateLangLinks( $entity, Language $lang ) {
		if ( !$entity || !isset( $entity['sitelinks'] ) ) {
			return [];
		}
		$languageLinks = [];
		$suffix = $this->config->get( 'UnlinkedWikibaseSitelinkSuffix' );
		$skip = $this->config->get( 'UnlinkedWikibaseSitelinkSkippedLangs' );
		$map = $this->config->get( MainConfigNames::InterlanguageLinkCodeMap );
		$langNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		foreach ( $entity['sitelinks'] as $wiki => $sitelink ) {
			if (
				strlen( $wiki ) <= strlen( $suffix ) ||
				substr( $wiki, -strlen( $suffix ) ) !== $suffix ) {
				// wrong wiki family
				continue;
			}
			$originalLangCode = substr( $wiki, 0, strlen( $wiki ) - strlen( $suffix ) );
			if ( in_array( $originalLangCode, $skip ) ) {
				continue;
			}

			// Based on Skin::getLanguages
			$class = "interlanguage-link interwiki-$originalLangCode";

			$langCode = $map[$originalLangCode] ?? $originalLangCode;
			$langName = $langNameUtils->getLanguageName( $langCode );
			if ( strval( $langName ) === '' ) {
				$msg = wfMessage( "interlanguage-link-$langCode" );
				if ( $msg->isDisabled() ) {
					// This seems odd, but copy what core does.
					$langName = $sitelink['title'];
				} else {
					$msg->text();
				}
			} else {
				$langName = $lang->ucFirst( $langName );
			}

			// Core would use user language, but we use parse language due to
			// where this processing happens with regards to caching.
			// This is only used in the tooltip anyways.
			$langLocalName = $langNameUtils->getLanguageName( $langCode, $lang->getCode() );
			if ( $langLocalName === '' ) {
				$friendlyName = wfMessage( "interlanguage-link-sitename-$langCode" );
				if ( $friendlyName->isDisabled() ) {
					$title = $originalLangCode . ":" . $sitelink['title'];
				} else {
					$title = wfMessage(
						'interlanguage-link-title-nonlang',
						$sitelink['title'],
						$friendlyName->text()
					)->text();
				}
			} else {
				$title = wfMessage( 'interlanguage-link-title', $sitelink['title'], $langLocalName )->text();
			}

			$langCodeBCP = LanguageCode::bcp47( $langCode );
			$languageLinks[] = [
				'href' => $sitelink['url'],
				'text' => $langName,
				'title' => $title,
				'class' => $class,
				'link-class' => 'interlanguage-link-target',
				'lang' => $langCodeBCP,
				'hreflang' => $langCodeBCP
			];
		}
		return $languageLinks;
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

	/**
	 * Propagate unlinked id to from parser output to output page for lang links
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $outputPage, $parserOutput ): void {
		$langLinks = $parserOutput->getExtensionData( self::LANG_LINKS );
		if ( is_array( $langLinks ) ) {
			$outputPage->setProperty( self::LANG_LINKS, $langLinks );
		}
	}

	/**
	 * Adjust language links based on entity for page, and add a toolbox link to the entity.
	 *
	 * The language links are only triggered when $wgUnlinkedWikibaseSitelinkSuffix is set.
	 *
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$langLinks = $skin->getOutput()->getProperty( self::LANG_LINKS );
		if ( is_array( $langLinks ) ) {
			$sidebar['LANGUAGES'] = array_merge( $sidebar['LANGUAGES'], $langLinks );
		}

		$props = MediaWikiServices::getInstance()
			->getPageProps()
			->getProperties( $skin->getTitle(), self::PAGE_PROP_ID );
		if ( $props ) {
			$entityId = array_shift( $props );
			$baseUrl = rtrim( $this->config->get( 'UnlinkedWikibaseBaseUrl' ), '/' );
			$sidebar['TOOLBOX']['unlinkedwikibase'] = [
				'msg' => 'unlinkedwikibase-sidebar-link',
				'href' => "$baseUrl/$entityId",
				'id' => 't-unlinkedwikibase-entity',
			];
		}
	}
}
