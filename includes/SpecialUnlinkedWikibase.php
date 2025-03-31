<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\QueryPage;
use MediaWiki\Title\Title;

class SpecialUnlinkedWikibase extends QueryPage {

	public function __construct() {
		parent::__construct( 'UnlinkedWikibase' );
	}

	/** @inheritDoc */
	public function getGroupName() {
		return 'wiki';
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$wb = new Wikibase();
		$queue = $this->msg( 'unlinkedwikibase-special-jobqueue-size', $wb->getJobQueueSize() );
		$out = Html::element( 'p', [], $queue );
		if ( !$wb->canCache() ) {
			$out .= Html::rawElement( 'p', [], $this->msg( 'unlinkedwikibase-special-no-jobs' )->parse() );
		}
		$this->getOutput()->addHTML( $out );
		return parent::execute( $par );
	}

	/** @inheritDoc */
	public function getQueryInfo() {
		$dbr = $this->getDatabaseProvider()->getReplicaDatabase();
		return [
			'tables' => [ 'page_props', 'page' ],
			'fields' => [
				'page_namespace AS namespace',
				'page_title AS title',
				'pp_value AS value',
			],
			'conds' => 'pp_propname ' . $dbr->buildLike( [ 'unlinkedwikibase_entities_used_', $dbr->anyString() ] ),
			'join_conds' => [ 'page' => [ 'JOIN', 'pp_page = page_id' ] ],

		];
	}

	/** @inheritDoc */
	protected function formatResult( $skin, $result ) {
		$pageTitle = Title::makeTitle( $result->namespace, $result->title );
		$linkRenderer = $this->getLinkRenderer();
		$pageLink = $linkRenderer->makeLink( $pageTitle );
		$wikibase = new Wikibase();
		$entityId = $result->value;
		$wikibaseUrl = $wikibase->getEntityUrl( $entityId );
		$wikibaseLink = $linkRenderer->makeExternalLink( $wikibaseUrl, $entityId, $this->getFullTitle() );
		$entity = $wikibase->getEntityData( $entityId );
		$label = '';
		if ( isset( $entity['labels'][$skin->getLanguageCode()->toBcp47Code()]['value'] ) ) {
			$label = $entity['labels'][$skin->getLanguageCode()->toBcp47Code()]['value'];
		} elseif ( isset( $entity['labels']['mul']['value'] ) ) {
			$label = $entity['labels']['mul']['value'];
		} elseif ( isset( $entity['labels']['en']['value'] ) ) {
			$label = $entity['labels']['en']['value'];
		}
		return $wikibaseLink . $this->msg( 'word-separator' )->escaped()
			. $this->msg( 'parentheses-start' )->escaped() . $label . $this->msg( 'parentheses-end' )->escaped()
			. $this->msg( 'colon-separator' )->escaped()
			. $pageLink;
	}
}
