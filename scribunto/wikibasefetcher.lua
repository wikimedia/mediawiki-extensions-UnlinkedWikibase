local wikibasefetcher = {}
local php

function wikibasefetcher.setupInterface( options )
	-- Remove setup function.
	wikibasefetcher.setupInterface = nil

	-- Copy the PHP callbacks to a local variable, and remove the global.
	php = mw_interface
	mw_interface = nil

	-- Install into the mw global.
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.wikibasefetcher = wikibasefetcher

	-- Indicate that we're loaded.
	package.loaded['mw.ext.wikibasefetcher'] = wikibasefetcher
end

-- --
-- Get data from a Wikibase site.
-- @param string id Wikibase entity ID, starting with 'Q'.
-- @return table Whatever is returned by the Wikibase API.
-- --
function wikibasefetcher.getEntity( id )
	return php.getEntity( id );
end

return wikibasefetcher
