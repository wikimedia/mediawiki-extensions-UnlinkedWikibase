<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

class FetchJob extends Job {

	public const JOB_NAME = 'UnlinkedWikibaseFetch';

	/** @var WANObjectCache */
	private $cache;

	/** @var Config */
	private $config;

	public function __construct( Title $title, array $params, WANObjectCache $cache, Config $config ) {
		parent::__construct( self::JOB_NAME, $params );
		$this->cache = $cache;
		$this->config = $config;
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$url = $this->getParams()['url'];
		$cacheKey = $this->cache->makeKey( 'ext-UnlinkedWikibase', $url );

		$data = $this->cache->get( $cacheKey );
		if ( $data ) {
			return true;
		}

		$ttl = $this->getParams()['ttl'];
		if ( !$ttl ) {
			$ttl = $this->config->get( 'UnlinkedWikibaseEntityTTL' );
		}
		if ( $ttl === null ) {
			$ttl = BagOStuff::TTL_INDEFINITE;
		}

		$wikibase = new Wikibase();
		$data = $wikibase->fetchWithoutCache( $url );
		$this->cache->set( $cacheKey, $data, $ttl, [ 'staleTTL' => BagOStuff::TTL_WEEK ] );

		return true;
	}
}
