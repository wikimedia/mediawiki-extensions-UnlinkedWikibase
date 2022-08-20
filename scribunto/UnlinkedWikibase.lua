local UnlinkedWikibase = {}
local php

function UnlinkedWikibase.setupInterface( options )
	-- Remove setup function.
	UnlinkedWikibase.setupInterface = nil

	-- Copy the PHP callbacks to a local variable, and remove the global.
	php = mw_interface
	mw_interface = nil

	-- Install into the mw global.
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.UnlinkedWikibase = UnlinkedWikibase

	-- Indicate that we're loaded.
	package.loaded['mw.ext.UnlinkedWikibase'] = UnlinkedWikibase
end

-- --
-- Get data from a Wikibase site.
-- @param string id Wikibase entity ID, starting with 'Q'.
-- @return table Whatever is returned by the Wikibase API.
-- --
function UnlinkedWikibase.getEntity( id )
	return php.getEntity( id );
end

-- --
-- Get the local title.
-- @param string entityId Wikibase entity ID, starting with 'Q'.
-- --
function UnlinkedWikibase.getLocalTitle( entityId )
	local pageId = php.getLocalPageId( entityId )
	if pageId == nil then
		return nil
	end
	return mw.title.new( pageId );
end

-- --
-- Fetch the results of a Sparql query from the Wikibase query service.
-- @param string query The Sparql query to execute.
-- --
function UnlinkedWikibase.query( query )
	return php.query( query );
end

return UnlinkedWikibase
