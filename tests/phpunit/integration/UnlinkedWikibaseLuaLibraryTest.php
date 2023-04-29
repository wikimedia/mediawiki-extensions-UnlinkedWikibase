<?php

namespace MediaWiki\Extension\UnlinkedWikibase\Test;

use MediaWiki\Extension\UnlinkedWikibase\UnlinkedWikibaseLuaLibrary;
use MediaWikiIntegrationTestCase;
use Scribunto_LuaEngine;

/**
 * @covers MediaWiki\Extension\UnlinkedWikibase\UnlinkedWikibaseLuaLibrary
 */
class UnlinkedWikibaseLuaLibraryTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideZeroIndexed
	 */
	public function testZeroIndexed( array $in, array $out ) {
		$engine = $this->createMock( Scribunto_LuaEngine::class );
		$lib = new UnlinkedWikibaseLuaLibrary( $engine );
		$this->assertSame( $out, $lib->arrayConvertToOneIndex( $in ) );
	}

	public function provideZeroIndexed() {
		return [
			'simple' => [
				[ 0 => 'a', 1 => 'b' ],
				[ 1 => 'a', 2 => 'b' ],
			],
			'not 0 indexed' => [
				[ 'c' => [ 1 => 'd' ], 1 => 'b' ],
				[ 'c' => [ 1 => 'd' ], 1 => 'b' ],
			],
			'not 0 indexed' => [
				[ 'c' => [ 'd' => 'e', 0 => 'f' ], 1 => 'b' ],
				[ 'c' => [ 'd' => 'e', 1 => 'f' ], 1 => 'b' ],
			],
		];
	}
}