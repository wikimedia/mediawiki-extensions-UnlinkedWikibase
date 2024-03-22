<?php

namespace MediaWiki\Extension\UnlinkedWikibase\Maintenance;

use Maintenance;
use MediaWiki\Extension\UnlinkedWikibase\Hooks;
use MediaWiki\Extension\UnlinkedWikibase\Wikibase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class UpdateEntities extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'UnlinkedWikibase' );
		$this->parameters->setDescription( "Refresh the UnlinkedWikibase cache of entities' data" );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		if ( $this->getConfig()->get( 'UnlinkedWikibaseEntityTTL' ) !== null ) {
			$this->output(
				"Note: \$wgUnlinkedWikibaseEntityTTL has a value, which means that entity\n"
				. "      data will *also* be fetched when pages are parsed. If you are using this\n"
				. "      maintenance script, you may want to set the above config variable to null.\n"
			);
		}
		$dbr = $this->getDB( DB_REPLICA );
		$previousQid = 'Q0';
		$wikibase = new Wikibase();
		$cache = $this->getServiceContainer()->getMainWANObjectCache();
		do {
			$rows = $dbr->newSelectQueryBuilder()
				->select( [ 'pp_value' ] )
				->from( 'page_props' )
				->where( [
					'pp_propname' . $dbr->buildLike( Hooks::PAGE_PROP_ENTITIES_USED_PREFIX, $dbr->anyString() ),
					'pp_value > ' . $dbr->addQuotes( $previousQid ),
				] )
				->distinct()
				->orderBy( 'pp_value' )
				->limit( $this->getBatchSize() )
				->caller( __METHOD__ );
			$entitieIds = [];
			foreach ( $rows->fetchResultSet() as $row ) {
				$previousQid = $row->pp_value;
				$entitieIds[] = $row->pp_value;
			}
			if ( !$entitieIds ) {
				continue;
			}
			$entities = $wikibase->getApiResult( [
				'action' => 'wbgetentities',
				'ids' => implode( '|', $entitieIds ),
				'format' => 'json',
				'formatversion' => 2
			] );
			foreach ( $entities['entities'] as $entity ) {
				$url = $wikibase->getEntityUrl( $entity['id'] );
				$cacheKey = $cache->makeKey( 'ext-UnlinkedWikibase', $url );
				// Replicate the result format of Special:EntityData/Qxx.json
				// We only want a single entity per cache data, so can't just cache $entities.
				$data = [ 'entities' => [ $entity['id'] => $entity ] ];
				$cache->set( $cacheKey, $data );
				$this->output( $entity['id'] . "\n" );
			}
		} while ( $rows->fetchRowCount() > 0 );
	}
}

$maintClass = UpdateEntities::class;
require_once RUN_MAINTENANCE_IF_MAIN;
