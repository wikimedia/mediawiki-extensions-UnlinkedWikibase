<?php

namespace MediaWiki\Extension\UnlinkedWikibase;

class UnlinkedWikibaseImitationLuaLibrary extends UnlinkedWikibaseLuaLibrary {

	/** @inheritDoc */
	protected function getLuaFileName(): string {
		return 'Imitation.lua';
	}

}
