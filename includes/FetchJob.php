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

		// These cache keys match what's used in `Wikibase::fetch()`.
		$cacheKey = $this->wikibase->makeCacheKey( $url );
		$cacheRefreshedKey = $this->wikibase->makeCacheKey( $url, 'refreshed' );

		$data = $cache->get( $cacheKey );
		$dataRefreshed = $cache->get( $cacheRefreshedKey );

		$ttl = $this->getParams()['ttl'] ?? null;
		if ( !$ttl ) {
			$ttl = $this->config->get( 'UnlinkedWikibaseEntityTTL' );
		}
		if ( $ttl === null ) {
			$ttl = $cache::TTL_INDEFINITE;
		}

		// If refreshed recently, do nothing.
		if ( $data !== false && $dataRefreshed !== false && (int)$dataRefreshed >= (int)wfTimestamp() - $ttl ) {
			return true;
		}

		$newData = $this->wikibase->fetchWithoutCache( $url );

		// On failure, leave the existing (possibly stale) data and timestamp untouched,
		// so Wikibase::fetch() keeps using it (another job will be queued when its next accessed).
		if ( $newData === [] ) {
			return true;
		}

		$cache->set( $cacheKey, $newData );
		$cache->set( $cacheRefreshedKey, wfTimestamp() );

		return true;
	}
}
