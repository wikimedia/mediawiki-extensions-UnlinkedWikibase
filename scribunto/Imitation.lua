local UnlinkedWikibase = require 'mw.ext.UnlinkedWikibase'
local php

function UnlinkedWikibase.setupInterface( options )
	UnlinkedWikibase.setupInterface = nil

	php = mw_interface
	mw_interface = nil

	mw = mw or {}
	mw.wikibase = UnlinkedWikibase
	package.loaded['mw.wikibase'] = UnlinkedWikibase
end

-- Returns the site global ID (the site code used for site links) of the current wiki.
-- Returns a value like "dewiki" or "eswikibooks"
function UnlinkedWikibase.getGlobalSiteId()
	return php.getCurrentWikiId()
end

return UnlinkedWikibase
