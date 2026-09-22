<?php

namespace MediaWiki\Extension\UnlinkedWikibase\Tests;

use MediaWiki\Extension\UnlinkedWikibase\Wikibase;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\UnlinkedWikibase\Wikibase
 * @group UnlinkedWikibase
 * @group Database
 */
class WikibaseTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	public function testFetchWithoutCache(): void {
		// No job should be pushed to the queue.
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )
			->method( 'lazyPush' );

		// One request should be made.
		$this->installMockHttp( $this->makeFakeHttpRequest( '[ "test data" ]' ) );

		// Run the fetch.
		$services = $this->getServiceContainer();
		$wikibase = new Wikibase(
			$services->getMainConfig(),
			$services->getHttpRequestFactory(),
			$services->getWANObjectCache(),
			$services->getContentLanguage(),
			$services->getLanguageFallback(),
			$jobQueueGroup,
			false
		);
		$data = $wikibase->fetch( 'https://example.org/', 500 );

		// The data was returned.
		$this->assertSame( [ "test data" ], $data );
	}

	public function testFetchWithCache(): void {
		// One job should be queued.
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' );

		// No requests should be made.
		$this->installMockHttp( [] );

		// Run the fetch.
		$cache = $this->makeCache( BagOStuff::QOS_DURABILITY_RDBMS );
		$services = $this->getServiceContainer();
		$wikibase = new Wikibase(
			$services->getMainConfig(),
			$services->getHttpRequestFactory(),
			$cache,
			$services->getContentLanguage(),
			$services->getLanguageFallback(),
			$jobQueueGroup,
			true
		);
		$data = $wikibase->fetch( 'https://example.org/', 500 );

		// No data was returned.
		$this->assertSame( [], $data );
	}

	/**
	 * Make a cache object with a faked QoS.
	 *
	 * @param int $qos
	 *
	 * @return WANObjectCache
	 */
	private function makeCache( int $qos ): WANObjectCache {
		$cache = $this->getServiceContainer()->getWANObjectCache();
		return new class( $cache, $qos ) extends WANObjectCache {
			private int $qos;

			public function __construct( WANObjectCache $cache, int $qos ) {
				parent::__construct( [ 'cache' => $cache ] );
				$this->qos = $qos;
			}

			public function getQoS( $flag ) {
				if ( $flag === BagOStuff::ATTR_DURABILITY ) {
					return $this->qos;
				}
				return parent::getQoS( $flag );
			}
		};
	}

}
