<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;

class UnlinkedWikibaseLuaLibrary extends LibraryBase {

	/** @var Config */
	private $config;

	/** @var WANObjectCache */
	private $cache;

	/** @var Wikibase */
	private $wikibase;

	/**
	 * @param LuaEngine $engine
	 */
	public function __construct( LuaEngine $engine ) {
		parent::__construct( $engine );
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getMainConfig();
		$this->cache = $services->getMainWANObjectCache();
		$this->wikibase = new Wikibase();
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
			'getLabel' => [ $this, 'getLabel' ],
			'getLabelByLanguage' => [ $this, 'getLabelByLanguage' ],
			'getDescription' => [ $this, 'getDescription' ],
			'getDescriptionByLanguage' => [ $this, 'getDescriptionByLanguage' ],
			'getSiteLinkPageName' => [ $this, 'getSiteLinkPageName' ],
			'getBadges' => [ $this, 'getBadges' ],
			'getLocalPageId' => [ $this, 'getLocalPageId' ],
			'getEntityId' => [ $this, 'getEntityId' ],
			'query' => [ $this, 'query' ],
			'getEntityStatements' => [ $this, 'getEntityStatements' ],
			'resolvePropertyId' => [ $this, 'resolvePropertyId' ],
		];
		$luaFile = dirname( __DIR__ ) . '/scribunto/' . $this->getLuaFileName();
		return $this->getEngine()->registerInterface( $luaFile, $interfaceFuncs );
	}

	protected function getLuaFileName(): string {
		return 'UnlinkedWikibase.lua';
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
		$entity = $this->wikibase->getEntity( $this->getParser(), $id );
		if ( $entity === null ) {
			return [ 'error' => 'item-not-found' ];
		}
		// Add schemaVersion for entity object validation
		$entity['schemaVersion'] = 2;
		// Get the first value of the result (keyed by ID, which might be different to the ID requested).
		return [ 'result' => $this->arrayConvertToOneIndex( $entity ) ];
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @return array{0:?string,1:?string} Array containing label, label language code.
	 *     Null for both, if entity couldn't be found/ no label present.
	 */
	public function getLabel( string $id ): array {
		return $this->wikibase->getLabel( $this->getParser(), $id );
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @param string $languageCode
	 * @return array{0:?string}
	 */
	public function getLabelByLanguage( string $id, string $languageCode ) {
		return [ $this->wikibase->getLabelByLanguage( $this->getParser(), $id, $languageCode ) ];
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @return array{0:?string,1:?string} Array containing description, description language code.
	 *     Null for both, if entity couldn't be found/ no description present.
	 */
	public function getDescription( string $id ): array {
		return $this->wikibase->getDescription( $this->getParser(), $id );
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @param string $languageCode
	 * @return array{0:?string}
	 */
	public function getDescriptionByLanguage( string $id, string $languageCode ) {
		return [ $this->wikibase->getDescriptionByLanguage( $this->getParser(), $id, $languageCode ) ];
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @param string|null $globalSiteId
	 * @return array{0:?string} Null if no site link found.
	 */
	public function getSiteLinkPageName( string $id, ?string $globalSiteId ): array {
		return [ $this->wikibase->getSiteLinkPageName( $this->getParser(), $id, $globalSiteId ) ];
	}

	/**
	 * @param string $id A Wikibase item ID.
	 * @param string|null $globalSiteId
	 * @return string[]
	 */
	public function getBadges( string $id, ?string $globalSiteId ): array {
		$badges = $this->wikibase->getBadges( $this->getParser(), $id, $globalSiteId );

		if ( !$badges ) {
			return [];
		}

		return $this->arrayConvertToOneIndex( $badges );
	}

	/**
	 * Convert zero-indexed arrays to one-indexed, for use in Lua.
	 *
	 * @param mixed[] $in
	 * @return mixed[]
	 */
	public function arrayConvertToOneIndex( array $in ) {
		$isZeroIndexed = isset( $in[0] );
		$out = [];
		foreach ( $in as $key => $val ) {
			if ( is_array( $val ) ) {
				$val = $this->arrayConvertToOneIndex( $val );
			}
			$newKey = $isZeroIndexed && is_numeric( $key ) ? $key + 1 : $key;
			$out[$newKey] = $val;
		}
		return $out;
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
		$out = $this->wikibase->fetch( $url, $this->config->get( 'UnlinkedWikibaseQueryTTL' ) );
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

	/**
	 * Get a Wikibase entity ID from a title page.
	 *
	 * @param string $title Title of the page.
	 * @return array
	 */
	public function getEntityId( string $title ) {
		$parser = $this->getParser();
		$method = __METHOD__;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'ext-UnlinkedWikibase', $method, $title ),
			WANObjectCache::TTL_MINUTE * 5,
			static function () use ( $title, $parser, $method ) {
				$parser->incrementExpensiveFunctionCount();

				$titleObject = Title::newFromText( $title );
				if ( !$titleObject ) {
					return [ 'result' => null ];
				}

				$dbr = MediaWikiServices::getInstance()
					->getDBLoadBalancer()
					->getConnection( DB_REPLICA );
				$entityId = $dbr->newSelectQueryBuilder()
					->select( 'pp_value' )
					->from( 'page_props' )
					->join( 'page', 'p', 'pp_page = p.page_id' )
					->where( [
						'pp_propname' => 'unlinkedwikibase_id',
						'p.page_title' => $titleObject->getDBkey(),
						'p.page_namespace' => $titleObject->getNamespace()
					] )
					->limit( 1 )
					->caller( $method )
					->fetchField();
				return [ 'result' => $entityId ];
			}
		);
	}

	/**
	 * Get statements from an entity.
	 *
	 * @param string $entityId A Wikibase entity ID.
	 * @param string $propertyId A Wikibase property ID.
	 * @param string $rank Which statements to include. Either "best" or "all".
	 * @return array Array of statements grouped by property ID.
	 */
	public function getEntityStatements( string $entityId, string $propertyId, string $rank ): array {
		$entity = $this->wikibase->getEntity( $this->getParser(), $entityId );
		if ( !$entity ) {
			return [];
		}

		$claims = $entity['claims'] ?? [];
		if ( !isset( $claims[$propertyId] ) ) {
			return [];
		}

		$statements = $claims[$propertyId];

		// If rank is "best", filter to get only the best ranked statements
		if ( $rank === 'best' ) {
			$statements = $this->filterBestStatements( $statements );
		}

		return [ 'result' => [ $propertyId => $this->arrayConvertToOneIndex( $statements ) ] ];
	}

	/**
	 * Filter statements to return only "best" ranked ones.
	 * Returns "preferred" statements if any exist, otherwise "normal" statements.
	 * Never returns "deprecated" statements.
	 *
	 * @param array $statements
	 * @return array
	 */
	private function filterBestStatements( array $statements ): array {
		$preferredStatements = [];
		$normalStatements = [];

		foreach ( $statements as $statement ) {
			$rank = $statement['rank'] ?? 'normal';
			if ( $rank === 'preferred' ) {
				$preferredStatements[] = $statement;
			} elseif ( $rank === 'normal' ) {
				$normalStatements[] = $statement;
			}
		}

		return $preferredStatements ?: $normalStatements;
	}

	/**
	 * Resolve a property label or ID to a property ID.
	 *
	 * @param string $propertyLabelOrId Property label or ID (e.g., "instance of" or "P31")
	 * @return array{0:string|null} Array containing the property ID or null if not found
	 */
	public function resolvePropertyId( string $propertyLabelOrId ): array {
		return [ $this->wikibase->getPropertyId( $this->getParser(), $propertyLabelOrId ) ];
	}
}
