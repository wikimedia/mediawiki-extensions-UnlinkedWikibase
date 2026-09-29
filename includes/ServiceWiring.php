<?php

use MediaWiki\Extension\UnlinkedWikibase\Wikibase;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

return [
	'UnlinkedWikibase.Wikibase' => static function ( MediaWikiServices $services ): Wikibase {
		$config = $services->getMainConfig();
		$cacheFactory = $services->getObjectCacheFactory();
		$unlinkedWikibaseCache = $config->get( 'UnlinkedWikibaseCache' );
		if ( $unlinkedWikibaseCache ) {
			$store = $cacheFactory->getInstance( $unlinkedWikibaseCache );
			$wanObjectCache = new WANObjectCache( [ 'cache' => $store, 'logger' => $store->getLogger() ] );
		} else {
			$store = $cacheFactory->getInstance( $config->get( MainConfigNames::MainCacheType ) );
			$wanObjectCache = $services->getWANObjectCache();
		}
		return new Wikibase(
			$config,
			$services->getHttpRequestFactory(),
			$wanObjectCache,
			$services->getContentLanguage(),
			$services->getLanguageFallback(),
			$services->getJobQueueGroup(),
			$store->getQoS( BagOStuff::ATTR_DURABILITY ) >= BagOStuff::QOS_DURABILITY_SERVICE,
		);
	},
];
