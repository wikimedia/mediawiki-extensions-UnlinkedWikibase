local UnlinkedWikibase = {}
local util = require 'libraryUtil'
local checkType = util.checkType
local checkTypeMulti = util.checkTypeMulti
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

-- Returns whether this a valid entity ID.
-- This does not check whether the entity in question exists,
-- it just checks that the entity id in question is valid.
function UnlinkedWikibase.isValidEntityId( id )
	return php.isValidEntityId( id )
end

-- Returns whether the entity in question exists.
-- Redirected entities are reported as existing too.
function UnlinkedWikibase.entityExists( id )
	return php.entityExists( id )
end

-- Renders a serialized Snak value to wikitext escaped plain text.
-- This is useful for displaying References or Qualifiers.
function UnlinkedWikibase.renderSnak( snak )
	return php.renderSnak( snak )
end

-- --
-- Get data from a Wikibase site and return it as an entity object.
-- @param string id Wikibase entity ID, starting with 'Q'.
-- @return table Entity object with methods, or nil if not found.
-- --
function UnlinkedWikibase.getEntity( id )
	local data = php.getEntity( id )

	if data == nil then
		return nil
	end

	local entityModule = require( 'mw.ext.UnlinkedWikibase.entity' )

	return entityModule.create( data )
end

-- --
-- Get raw entity data from a Wikibase site (without entity object wrapper).
-- @param string id Wikibase entity ID, starting with 'Q'.
-- @return table Whatever is returned by the Wikibase API.
-- --
function UnlinkedWikibase.getEntityObject( id )
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

-- --
-- Get the entity id for wikibase.
-- @param string title Local title.
-- @return string The Wikibase entity ID.
-- --
function UnlinkedWikibase.getEntityId( title )
	if not title then
		return nil
	end
	return php.getEntityId( title )
end

-- --
-- Get a wikibase entity ID for Current Page.
-- @return string The Wikibase entity ID.
-- --
function UnlinkedWikibase.getEntityIdForCurrentPage()
	return UnlinkedWikibase.getEntityId( mw.title.getCurrentTitle().prefixedText )
end

-- Get the label, label language for the given entity id, if specified,
-- or of the connected entity, if exists.
--
-- @param {string} [id]
function UnlinkedWikibase.getLabelWithLang( id )
	checkTypeMulti( 'getLabel', 1, id, { 'string', 'nil' } )
	return php.getLabel( id )
end

-- Like UnlinkedWikibase.getLabelWithLang, but only returns the plain label.
--
-- @param {string} [id]
function UnlinkedWikibase.getLabel( id )
	checkTypeMulti( 'getLabel', 1, id, { 'string', 'nil' } )
	local label, lang = UnlinkedWikibase.getLabelWithLang( id )
	return label
end

-- Get the label in languageCode for the given entity id.
--
-- @param {string} id
-- @param {string} languageCode
function UnlinkedWikibase.getLabelByLang( id, languageCode )
	checkType( 'getLabelByLang', 1, id, 'string' )
	checkType( 'getLabelByLang', 2, languageCode, 'string' )
	return php.getLabelByLanguage( id, languageCode )
end

-- Get the description in languageCode for the given entity id.
--
-- @param {string} id
-- @param {string} languageCode
function UnlinkedWikibase.getDescriptionByLang( id, languageCode )
	checkType( 'getDescriptionByLang', 1, id, 'string' )
	checkType( 'getDescriptionByLang', 2, languageCode, 'string' )
	return php.getDescriptionByLanguage( id, languageCode )
end

-- Get the description, description language for the given entity id, if specified,
-- or of the connected entity, if exists.
--
-- @param {string} [id]
function UnlinkedWikibase.getDescriptionWithLang( id )
	checkTypeMulti( 'getDescriptionWithLang', 1, id, { 'string', 'nil' } )
	return php.getDescription( id )
end

-- Like UnlinkedWikibase.getDescriptionWithLang, but only returns the plain description.
--
-- @param {string} [id]
function UnlinkedWikibase.getDescription( id )
	checkTypeMulti( 'getDescription', 1, id, { 'string', 'nil' } )
	local description, lang = UnlinkedWikibase.getDescriptionWithLang( id )
	return description
end

-- Get the local sitelink title for the given entity id.
--
-- @param {string} itemId
-- @param {string} [globalSiteId]
function UnlinkedWikibase.getSitelink( itemId, globalSiteId )
	checkType( 'getSitelink', 1, itemId, 'string' )
	checkTypeMulti( 'getSitelink', 2, globalSiteId, { 'string', 'nil' } )
	return php.getSiteLinkPageName( itemId, globalSiteId )
end

-- Return a list of badges from an item for a certain site (or the local wiki).
--
-- @param {string} itemId
-- @param {string} [globalSiteId]
function UnlinkedWikibase.getBadges( itemId, globalSiteId )
	checkType( 'getBadges', 1, itemId, 'string' )
	checkTypeMulti( 'getBadges', 2, globalSiteId, { 'string', 'nil' } )
	return php.getBadges( itemId, globalSiteId )
end

-- Returns a table with the "best" statements matching the given property ID on the given entity
-- ID. The definition of "best" is that the function will return "preferred" statements, if
-- there are any, otherwise "normal" ranked statements. It will never return "deprecated"
-- statements. This is what you usually want when surfacing values to an ordinary reader.
--
-- @param {string} entityId
-- @param {string} propertyId
function UnlinkedWikibase.getBestStatements( entityId, propertyId )
	checkType( 'getBestStatements', 1, entityId, 'string' )
	checkType( 'getBestStatements', 2, propertyId, 'string' )
	local statements = php.getEntityStatements( entityId, propertyId, 'best' )
	if statements and statements[propertyId] then
		return statements[propertyId]
	end
	return {}
end

-- Returns a table with all statements (including all ranks, even "deprecated") matching the
-- given property ID on the given entity ID.
--
-- @param {string} entityId
-- @param {string} propertyId
function UnlinkedWikibase.getAllStatements( entityId, propertyId )
	checkType( 'getAllStatements', 1, entityId, 'string' )
	checkType( 'getAllStatements', 2, propertyId, 'string' )
	local statements = php.getEntityStatements( entityId, propertyId, 'all' )
	if statements and statements[propertyId] then
		return statements[propertyId]
	end
	return {}
end

-- Returns a property id for the given label or id
--
-- @param {string} propertyLabelOrId
function UnlinkedWikibase.resolvePropertyId( propertyLabelOrId )
	checkType( 'resolvePropertyId', 1, propertyLabelOrId, 'string' )

	return php.resolvePropertyId( propertyLabelOrId )
end

return UnlinkedWikibase
