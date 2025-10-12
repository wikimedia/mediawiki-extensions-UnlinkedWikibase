local Entity = require 'mw.ext.UnlinkedWikibase.entity'

function Entity.setupInterface( options )
	mw = mw or {}
	mw.wikibase = mw.wikibase or {}
	mw.wikibase.entity = Entity
	package.loaded['mw.wikibase.entity'] = Entity
end

return Entity
