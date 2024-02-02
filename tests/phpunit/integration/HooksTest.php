<?php

namespace MediaWiki\Extension\UnlinkedWikibase\Test;

use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;

/**
 * @covers MediaWiki\Extension\UnlinkedWikibase\Hooks
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	private function getRequest( string $id ) {
		$request1 = $this->createMock( MWHttpRequest::class );
		$request1->method( 'execute' )->willReturn( new StatusValue() );
		$request1->method( 'getContent' )
			->willReturn( json_encode( [
				'entities' => [ [ 'id' => $id ] ]
			] ) );
		return $request1;
	}

	public function testEntityUsageIsSavedToProperties() {
		$this->installMockHttp( [
			$this->getRequest( 'Q123' ),
			$this->getRequest( 'Q456' ),
		] );

		$this->editPage( 'Module:Test', '
		local p = {}
		p.main = function ( frame )
			mw.ext.UnlinkedWikibase.getEntity( frame.args[1] );
		end
		return p
		' );

		$this->editPage( 'Entity page', '{{#unlinkedwikibase:|id=Q123}}' );
		$this->editPage( 'Query page 1', '{{#invoke:test|main|Q123}}{{#invoke:test|main|Q456}}' );
		$this->editPage( 'Query page 2', '{{#invoke:test|main|Q123}}' );
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
