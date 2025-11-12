local Entity = {}
local metatable = {}
local methodtable = {}
local settings = {}
local util = require 'libraryUtil'
local checkType = util.checkType
local checkTypeMulti = util.checkTypeMulti

metatable.__index = methodtable

-- Claim ranks (Claim::RANK_* in PHP)
Entity.claimRanks = {
	RANK_TRUTH = 3,
	RANK_PREFERRED = 2,
	RANK_NORMAL = 1,
	RANK_DEPRECATED = 0
}

function Entity.setupInterface( options )
	settings = options
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.UnlinkedWikibase.entity = Entity

	package.loaded['mw.ext.UnlinkedWikibase.entity'] = Entity
end

-- Is this a valid property id (Pnnn)?
--
-- @param {string} propertyId
local function isValidPropertyId( propertyId )
	return type( propertyId ) == 'string' and propertyId:match( '^P[1-9]%d*$' )
end

-- Create new entity object from given data
--
-- @param {table} data
function Entity.create( data )
	if type( data ) ~= 'table' then
		error( 'Expected a table obtained via mw.ext.UnlinkedWikibase.getEntity, got ' .. type( data ) .. ' instead' )
	end
	if next( data ) == nil then
		error( 'Expected a non-empty table obtained via mw.ext.UnlinkedWikibase.getEntity' )
	end
	if type( data.id ) ~= 'string' then
		error( 'data.id must be a string, got ' .. type( data.id ) .. ' instead' )
	end
	if type( data.schemaVersion ) ~= 'number' then
		error( 'data.schemaVersion must be a number, got ' .. type( data.schemaVersion ) .. ' instead' )
	end
	if data.schemaVersion < 2 then
		error( 'mw.ext.UnlinkedWikibase.entity must not be constructed using legacy data' )
	end

	local entity = data
	setmetatable( entity, metatable )
	return entity
end

-- Get the id serialization from this entity.
function methodtable.getId( entity )
	return entity.id
end

-- Get a term of a given type for a given language code or the content language (on monolingual wikis)
-- or the user's language (on multilingual wikis).
-- Second return parameter is the language the term is in.
--
-- @param {table} entity
-- @param {string} termType A valid key in the entity table (either labels or descriptions)
-- @param {string|number} langCode
local function getTermAndLang( entity, termType, langCode )
	langCode = langCode or settings.languageCode

	if langCode == nil then
		return nil, nil
	end

	if entity[termType] == nil then
		return nil, nil
	end

	local term = entity[termType][langCode]

	if term == nil then
		return nil, nil
	end

	local actualLang = term.language or langCode
	return term.value, actualLang
end

-- Get the label for a given language code or the content language (on monolingual wikis)
-- or the user's language (on multilingual wikis).
--
-- @param {string|number} [langCode]
function methodtable.getLabel( entity, langCode )
	checkTypeMulti( 'getLabel', 1, langCode, { 'string', 'number', 'nil' } )

	local label = getTermAndLang( entity, 'labels', langCode )
	return label
end

-- Get the description for a given language code or the content language (on monolingual wikis)
-- or the user's language (on multilingual wikis).
--
-- @param {string|number} [langCode]
function methodtable.getDescription( entity, langCode )
	checkTypeMulti( 'getDescription', 1, langCode, { 'string', 'number', 'nil' } )

	local description = getTermAndLang( entity, 'descriptions', langCode )
	return description
end

-- Get the label for a given language code or the content language (on monolingual wikis)
-- or the user's language (on multilingual wikis).
-- Has the language the returned label is in as an additional second return parameter.
--
-- @param {string|number} [langCode]
function methodtable.getLabelWithLang( entity, langCode )
	checkTypeMulti( 'getLabelWithLang', 1, langCode, { 'string', 'number', 'nil' } )

	return getTermAndLang( entity, 'labels', langCode )
end

-- Get the description for a given language code or the content language (on monolingual wikis)
-- or the user's language (on multilingual wikis).
-- Has the language the returned description is in as an additional second return parameter.
--
-- @param {string|number} [langCode]
function methodtable.getDescriptionWithLang( entity, langCode )
	checkTypeMulti( 'getDescriptionWithLang', 1, langCode, { 'string', 'number', 'nil' } )

	return getTermAndLang( entity, 'descriptions', langCode )
end

-- Get the sitelink title linking to the given site id
--
-- @param {string} [globalSiteId]
function methodtable.getSitelink( entity, globalSiteId )
	checkTypeMulti( 'getSitelink', 1, globalSiteId, { 'string', 'nil' } )

	if entity.sitelinks == nil then
		return nil
	end

	globalSiteId = globalSiteId or settings.globalSiteId

	if globalSiteId == nil then
		return nil
	end

	local sitelink = entity.sitelinks[globalSiteId]

	if sitelink == nil then
		return nil
	end

	return sitelink.title
end

-- Get badges for a given site
--
-- @param {string} [globalSiteId]
function methodtable.getBadges( entity, globalSiteId )
	checkTypeMulti( 'getBadges', 1, globalSiteId, { 'string', 'nil' } )

	if entity.sitelinks == nil then
		return {}
	end

	globalSiteId = globalSiteId or settings.globalSiteId

	if globalSiteId == nil then
		return {}
	end

	local sitelink = entity.sitelinks[globalSiteId]

	if sitelink == nil then
		return {}
	end

	return sitelink.badges
end

-- Get the best statements with the given property id
--
-- @param {string} propertyLabelOrId
function methodtable.getBestStatements( entity, propertyLabelOrId )
	checkType( 'getBestStatements', 1, propertyLabelOrId, 'string' )

	local propertyId = propertyLabelOrId
	if not isValidPropertyId( propertyId ) then
		propertyId = mw.ext.UnlinkedWikibase.resolvePropertyId( propertyId )
	end

	if not propertyId then
		return {}
	end

	return mw.ext.UnlinkedWikibase.getBestStatements( entity.id, propertyId )
end

-- Get all statements with the given property id
--
-- @param {string} propertyLabelOrId
function methodtable.getAllStatements( entity, propertyLabelOrId )
	checkType( 'getAllStatements', 1, propertyLabelOrId, 'string' )

	local propertyId = propertyLabelOrId
	if not isValidPropertyId( propertyId ) then
		propertyId = mw.ext.UnlinkedWikibase.resolvePropertyId( propertyId )
	end

	if not propertyId then
		return {}
	end

	return mw.ext.UnlinkedWikibase.getAllStatements( entity.id, propertyId )
end

return Entity
