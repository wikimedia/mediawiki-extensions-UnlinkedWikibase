<?php

namespace MediaWiki\Extension\UnlinkedWikibase\Test;

use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Wikimedia\Rdbms\IDatabase;

/**
 * @covers MediaWiki\Extension\UnlinkedWikibase\Hooks
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	public function testEntityUsageIsSavedToProperties() {
		$this->installMockHttp( function ( $url ) {
			preg_match( '/(Q[0-9]+)/', $url, $matches );
			return $this->makeFakeHttpRequest( json_encode( [
				'entities' => [ [ 'id' => $matches[1] ?? null ] ],
			] ) );
		} );
		$this->overrideConfigValue( 'UnlinkedWikibaseSitelinkSuffix', null );

		$this->editPage( 'Module:Test', '
		local p = {}
		p.main = function ( frame )
			mw.ext.UnlinkedWikibase.getEntity( frame.args[1] );
		end
		return p
		' );

		$entityPage = $this->editPage( 'Entity page', '{{#unlinkedwikibase:|id=Q123}}' );
		$this->assertSame( 2, $entityPage->getValue()['revision-record']->getId() );
		$page1 = $this->editPage( 'Query page 1', '{{#invoke:test|main|Q123}}{{#invoke:test|main|Q456}}' );
		$this->assertSame( 3, $page1->getValue()['revision-record']->getId() );
		$page2 = $this->editPage( 'Query page 2', '{{#invoke:test|main|Q123}}' );
		$this->assertSame( 4, $page2->getValue()['revision-record']->getId() );
		$this->assertSelect(
			'page_props',
			[ 'pp_page', 'pp_propname', 'pp_value' ],
			IDatabase::ALL_ROWS,
			[
				[ '2', 'unlinkedwikibase_id', 'Q123' ],
				[ '3', 'unlinkedwikibase_entities_used_1', 'Q123' ],
				[ '3', 'unlinkedwikibase_entities_used_2', 'Q456' ],
				[ '4', 'unlinkedwikibase_entities_used_1', 'Q123' ],
			]
		);
	}

}
