<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

class UnlinkedWikibaseEntityImitationLuaLibrary extends UnlinkedWikibaseEntityLuaLibrary {

	/** @inheritDoc */
	protected function getLuaFileName(): string {
		return 'Imitation.entity.lua';
	}

}
