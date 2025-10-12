local UnlinkedWikibase = require 'mw.ext.UnlinkedWikibase'
local php

function UnlinkedWikibase.setupInterface( options )
	mw = mw or {}
	mw.wikibase = UnlinkedWikibase
	package.loaded['mw.wikibase'] = UnlinkedWikibase
end

return UnlinkedWikibase
