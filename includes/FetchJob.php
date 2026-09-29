<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Title\Title;

class FetchJob extends Job {

	public const JOB_NAME = 'UnlinkedWikibaseFetch';

	public function __construct(
		Title $title,
		array $params,
		private readonly Config $config,
		private readonly Wikibase $wikibase,
	) {
		parent::__construct( self::JOB_NAME, $params );
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$url = $this->getParams()['url'];
		$cache = $this->wikibase->getCache();
		$cacheKey = $cache->makeKey( 'ext-UnlinkedWikibase', $url );

		$data = $cache->get( $cacheKey );
		if ( $data ) {
			return true;
		}

		$ttl = $this->getParams()['ttl'];
		if ( !$ttl ) {
			$ttl = $this->config->get( 'UnlinkedWikibaseEntityTTL' );
		}
		if ( $ttl === null ) {
			$ttl = $cache::TTL_INDEFINITE;
		}

		$data = $this->wikibase->fetchWithoutCache( $url );
		$cache->set( $cacheKey, $data, $ttl, [ 'staleTTL' => $cache::TTL_WEEK ] );

		return true;
	}
}
