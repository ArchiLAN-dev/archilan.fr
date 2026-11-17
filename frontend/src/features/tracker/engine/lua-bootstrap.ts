/**
 * The Lua side of the engine (story 54.4), run once before the pack's `scripts/init.lua`.
 *
 * It receives the JS bridge as the global `__pt_bridge`, keeps it in a local and removes the global: the pack only
 * ever reaches JS through the shim below, and only with strings, numbers and booleans. It then closes the sandbox
 * (no `io`, `debug`, `package`, bytecode, file loading, and `os` reduced to time) and returns the functions the
 * engine calls (rules, watchers, scripts).
 *
 * Written from the PopTracker spec (`doc/PACKS.md`, `doc/AUTOTRACKING.md`); no PopTracker code is reused.
 */
export const LUA_BOOTSTRAP = String.raw`
local B = __pt_bridge
__pt_bridge = nil

local rawload = load
local select, type, tostring, pairs, ipairs, error, pcall, setmetatable, rawset =
  select, type, tostring, pairs, ipairs, error, pcall, setmetatable, rawset
local concat = table.concat
local find, sub = string.find, string.sub
local tointeger, utf8char, tonumber = math.tointeger, utf8.char, tonumber
local os_time, os_clock, os_date = os.time, os.clock, os.date
local gc = collectgarbage

-- Sandbox -------------------------------------------------------------------
io = nil
debug = nil
package = nil
dofile = nil
loadfile = nil
os = { time = os_time, clock = os_clock, date = os_date }
string.dump = nil
collectgarbage = function(option)
  if option == "count" then return gc("count") end
  return 0
end
load = function(chunk, name, mode, ...)
  if select("#", ...) > 0 then return rawload(chunk, name, "t", ...) end
  return rawload(chunk, name, "t")
end

print = function(...)
  local parts = {}
  for i = 1, select("#", ...) do parts[i] = tostring((select(i, ...))) end
  B.log("info", concat(parts, "\t"))
end

-- Unknown functions --------------------------------------------------------
local function unsupported(owner, name)
  return function()
    B.unsupported(owner .. ":" .. name)
    error("unsupported_api: " .. owner .. ":" .. name, 2)
  end
end

local memoryWarned = {}
local function memoryOnly(owner, name)
  return function()
    local key = owner .. ":" .. name
    if not memoryWarned[key] then
      memoryWarned[key] = true
      B.log("warn", "autotracking mémoire non pris en charge (" .. key .. ")")
    end
    return nil
  end
end

-- Objects (items, sections, locations) -------------------------------------
local proxies = {}
local refs = setmetatable({}, { __mode = "k" })
local stores = {}

local methods = {}
function methods.SetOverlay(self, text) B.set(refs[self], "BadgeText", tostring(text or "")) end
function methods.SetOverlayColor(self, color) B.set(refs[self], "BadgeTextColor", tostring(color or "")) end
function methods.SetOverlayBackground(self, color) B.set(refs[self], "OverlayBackground", tostring(color or "")) end
function methods.SetOverlayFontSize(self, size) B.set(refs[self], "OverlayFontSize", size) end
function methods.SetOverlayAlign(self, align) B.set(refs[self], "OverlayAlign", tostring(align or "")) end
function methods.Get(self, key)
  local store = stores[refs[self]]
  return store and store[key]
end
function methods.Set(self, key, value)
  local ref = refs[self]
  stores[ref] = stores[ref] or {}
  stores[ref][key] = value
end

local function proxy(ref)
  if ref == nil then return nil end
  local existing = proxies[ref]
  if existing then return existing end
  local object = setmetatable({}, {
    __index = function(_, key)
      local method = methods[key]
      if method then return method end
      if key == "Owner" then return {} end
      if key == "ItemState" then
        stores[ref] = stores[ref] or {}
        return stores[ref]
      end
      return B.get(ref, key)
    end,
    __newindex = function(_, key, value) B.set(ref, key, value) end,
    __tostring = function() return "TrackerObject(" .. ref .. ")" end,
  })
  proxies[ref] = object
  refs[object] = ref
  return object
end

-- Tracker -------------------------------------------------------------------
local tracker = {}
rawset(tracker, "ActiveVariantUID", B.variant())
function tracker:AddItems(file) return B.add("items", file) end
function tracker:AddMaps(file) return B.add("maps", file) end
function tracker:AddLocations(file) return B.add("locations", file) end
function tracker:AddLayouts(file) return B.add("layouts", file) end
function tracker:AddClasses(file)
  B.log("info", "Tracker:AddClasses ignoré (" .. tostring(file) .. ")")
  return true
end
function tracker:ProviderCountForCode(code)
  if type(code) ~= "string" then return 0 end
  return B.count(code)
end
function tracker:FindObjectForCode(code)
  if type(code) ~= "string" then return nil end
  return proxy(B.find(code))
end
function tracker:UiHint(name, value)
  B.log("info", "Tracker:UiHint ignoré (" .. tostring(name) .. ")")
end
Tracker = setmetatable(tracker, {
  __index = function(_, key)
    if key == "BulkUpdate" or key == "AllowDeferredLogicUpdate" then return B.flag(key) end
    return unsupported("Tracker", key)
  end,
  __newindex = function(t, key, value)
    if key == "BulkUpdate" or key == "AllowDeferredLogicUpdate" then
      B.setFlag(key, value == true)
      return
    end
    rawset(t, key, value)
  end,
})

-- Scripts and require --------------------------------------------------------
local loaded = {}

local function run(path, source, ...)
  local chunk, message = rawload(source, "@" .. path, "t")
  if not chunk then error(message, 0) end
  return chunk(...)
end

local function loadScript(path)
  if type(path) ~= "string" then return false end
  local source = B.readScript(path)
  if source == nil then
    B.log("error", "Script introuvable : " .. path)
    return false
  end
  run(path, source)
  return true
end

require = function(name)
  if type(name) ~= "string" then error("require: nom invalide", 2) end
  if loaded[name] ~= nil then return loaded[name] end
  local path = name:gsub("%.", "/")
  local candidates = { "scripts/" .. path .. ".lua", "scripts/" .. path .. "/init.lua", path .. ".lua", path .. "/init.lua" }
  for _, candidate in ipairs(candidates) do
    local source = B.readScript(candidate)
    if source ~= nil then
      local result = run(candidate, source, name, candidate)
      if result == nil then result = true end
      loaded[name] = result
      return result
    end
  end
  error("module '" .. name .. "' introuvable dans le pack", 2)
end

-- ScriptHost ------------------------------------------------------------------
local watches = {}
local watchOrder = {}
local sectionHandlers = {}
local sectionOrder = {}

local scriptHost = {}
function scriptHost:LoadScript(path) return loadScript(path) end
function scriptHost:AddWatchForCode(name, code, callback)
  if watches[name] == nil then watchOrder[#watchOrder + 1] = name end
  watches[name] = { code = code, callback = callback }
  return name
end
function scriptHost:RemoveWatchForCode(name)
  if watches[name] == nil then return false end
  watches[name] = nil
  return true
end
function scriptHost:AddOnLocationSectionChangedHandler(name, callback)
  if sectionHandlers[name] == nil then sectionOrder[#sectionOrder + 1] = name end
  sectionHandlers[name] = callback
  return name
end
function scriptHost:RemoveOnLocationSectionChangedHandler(name)
  if sectionHandlers[name] == nil then return false end
  sectionHandlers[name] = nil
  return true
end
scriptHost.RemoveOnLocationSectionHandler = scriptHost.RemoveOnLocationSectionChangedHandler
for _, name in ipairs({ "AddMemoryWatch", "RemoveMemoryWatch", "AddVariableWatch", "RemoveVariableWatch",
  "AddOnFrameHandler", "RemoveOnFrameHandler" }) do
  scriptHost[name] = memoryOnly("ScriptHost", name)
end
ScriptHost = setmetatable(scriptHost, { __index = function(_, key) return unsupported("ScriptHost", key) end })

-- AutoTracker, Archipelago --------------------------------------------------------
local autoTracker = {}
AutoTracker = setmetatable(autoTracker, { __index = function(_, key) return memoryOnly("AutoTracker", key) end })

-- Archipelago (story 54.6): the page holds the connection, the engine forwards its events here. PopTracker's
-- connection states as packs test them: 0 disconnected, 3 connected.
local apConnection = 0
autoTracker.GetConnectionState = function(self, name)
  if name == "AP" then return apConnection end
  return 0
end

local apHandlers = {}
local archipelago = { PlayerNumber = -1, TeamNumber = -1, CheckedLocations = {}, MissingLocations = {} }
for _, kind in ipairs({ "Clear", "Item", "Location", "Scout", "Bounced", "Retrieved", "SetReply" }) do
  apHandlers[kind] = {}
  archipelago["Add" .. kind .. "Handler"] = function(self, name, callback)
    local list = apHandlers[kind]
    list[#list + 1] = { name = name, callback = callback }
    return true
  end
end
local function apRequest(cmd, keys)
  if apConnection ~= 3 or type(keys) ~= "table" then return false end
  for _, key in ipairs(keys) do B.apSend(cmd, tostring(key)) end
  return true
end
function archipelago:Get(keys) return apRequest("Get", keys) end
function archipelago:SetNotify(keys) return apRequest("SetNotify", keys) end
function archipelago:GetPlayerAlias(slot) return B.apPlayer("alias", slot) end
function archipelago:GetPlayerGame(slot) return B.apPlayer("game", slot) end
function archipelago:GetItemName(id, game) return B.apName("item", id, game or "") end
function archipelago:GetLocationName(id, game) return B.apName("location", id, game or "") end
-- LocationChecks, LocationScouts and StatusUpdate write to the room: the site's tracker never does (apmanual packs).
Archipelago = setmetatable(archipelago, { __index = function(_, key) return unsupported("Archipelago", key) end })
ClientStatus = { UNKNOWN = 0, CONNECTED = 5, READY = 10, PLAYING = 20, GOAL = 30 }

-- JSON, for the events the engine forwards (slot data, data storage values). null becomes nil.
local escapes = { b = "\b", f = "\f", n = "\n", r = "\r", t = "\t", ['"'] = '"', ["\\"] = "\\", ["/"] = "/" }
local function decodeJson(text)
  local pos = 1
  local function fail(what) error("JSON : " .. what .. " (position " .. pos .. ")", 0) end
  local function skip() pos = find(text, "[^ \t\r\n]", pos) or (#text + 1) end
  local function str()
    pos = pos + 1
    local parts = {}
    while true do
      local at = find(text, '["\\]', pos)
      if not at then fail("chaîne non terminée") end
      parts[#parts + 1] = sub(text, pos, at - 1)
      if sub(text, at, at) == '"' then
        pos = at + 1
        return concat(parts)
      end
      local c = sub(text, at + 1, at + 1)
      if c == "u" then
        local code = tonumber(sub(text, at + 2, at + 5), 16) or fail("échappement invalide")
        local after = at + 6
        if code >= 0xD800 and code <= 0xDBFF and sub(text, after, after + 1) == "\\u" then
          local low = tonumber(sub(text, after + 2, after + 5), 16)
          if low and low >= 0xDC00 and low <= 0xDFFF then
            code = 0x10000 + (code - 0xD800) * 0x400 + (low - 0xDC00)
            after = after + 6
          end
        end
        parts[#parts + 1] = utf8char(code)
        pos = after
      else
        parts[#parts + 1] = escapes[c] or fail("échappement invalide")
        pos = at + 2
      end
    end
  end
  local value
  value = function()
    skip()
    local c = sub(text, pos, pos)
    if c == "{" then
      local object = {}
      pos = pos + 1
      skip()
      if sub(text, pos, pos) == "}" then
        pos = pos + 1
        return object
      end
      while true do
        skip()
        if sub(text, pos, pos) ~= '"' then fail("clé attendue") end
        local key = str()
        skip()
        if sub(text, pos, pos) ~= ":" then fail("« : » attendu") end
        pos = pos + 1
        object[key] = value()
        skip()
        local d = sub(text, pos, pos)
        pos = pos + 1
        if d == "}" then return object end
        if d ~= "," then fail("« , » attendue") end
      end
    elseif c == "[" then
      local list, n = {}, 0
      pos = pos + 1
      skip()
      if sub(text, pos, pos) == "]" then
        pos = pos + 1
        return list
      end
      while true do
        n = n + 1
        list[n] = value()
        skip()
        local d = sub(text, pos, pos)
        pos = pos + 1
        if d == "]" then return list end
        if d ~= "," then fail("« , » attendue") end
      end
    elseif c == '"' then
      return str()
    elseif sub(text, pos, pos + 3) == "true" then
      pos = pos + 4
      return true
    elseif sub(text, pos, pos + 4) == "false" then
      pos = pos + 5
      return false
    elseif sub(text, pos, pos + 3) == "null" then
      pos = pos + 4
      return nil
    end
    local from, to = find(text, "^-?%d+%.?%d*[eE]?[-+]?%d*", pos)
    if not from then fail("caractère inattendu") end
    local number = tonumber(sub(text, from, to)) or fail("nombre invalide")
    pos = to + 1
    return tointeger(number) or number
  end
  return value()
end

local function callAll(kind, ...)
  for _, handler in ipairs(apHandlers[kind]) do
    local ok, message = pcall(handler.callback, ...)
    if not ok then
      B.log("error", "handler Archipelago " .. kind .. " « " .. tostring(handler.name) .. " » : " .. tostring(message))
    end
  end
end

local function applyApEvent(event)
  local kind = event.kind
  if kind == "connected" then
    archipelago.PlayerNumber = event.playerNumber
    archipelago.TeamNumber = event.teamNumber
    archipelago.Seed = event.seed
    archipelago.CheckedLocations = event.checked or {}
    archipelago.MissingLocations = event.missing or {}
    apConnection = 3
  elseif kind == "disconnected" then
    apConnection = 0
  elseif kind == "clear" then
    callAll("Clear", event.slotData or {})
  elseif kind == "items" then
    for _, item in ipairs(event.items or {}) do
      callAll("Item", item.index, item.item, B.apName("item", item.item, ""), item.player)
    end
  elseif kind == "locations" then
    local checked = archipelago.CheckedLocations
    for _, id in ipairs(event.locations or {}) do
      checked[#checked + 1] = id
      callAll("Location", id, B.apName("location", id, ""))
    end
  elseif kind == "scouted" then
    for _, info in ipairs(event.locations or {}) do
      local game = B.apPlayer("game", info.player) or ""
      callAll("Scout", info.location, B.apName("location", info.location, ""), info.item,
        B.apName("item", info.item, game), info.player)
    end
  elseif kind == "bounced" then
    callAll("Bounced", event.data or {})
  elseif kind == "retrieved" then
    callAll("Retrieved", event.key, event.value)
  elseif kind == "setReply" then
    callAll("SetReply", event.key, event.value, event.originalValue)
  end
end

ImageReference = {}
function ImageReference:FromPackRelativePath(path) return path end
function ImageReference:FromImageReference(original, mod) return tostring(original) .. tostring(mod or "") end

AccessibilityLevel = { None = 0, Partial = 1, Inspect = 3, SequenceBreak = 5, Normal = 6, Cleared = 7 }
Highlight = { Avoid = -1, None = 0, NoPriority = 1, Unspecified = 2, Priority = 3 }
PopVersion = B.popVersion()

-- Engine entry points ---------------------------------------------------------------
local api = {}

function api.loadScript(path)
  return loadScript(path)
end

-- The value of a rule function, or nil when it is missing or fails (the error is logged).
function api.callRule(name, ...)
  local f = _G[name]
  if type(f) ~= "function" then
    B.ruleError(name, "fonction absente")
    return nil
  end
  local ok, value = pcall(f, ...)
  if not ok then
    B.ruleError(name, tostring(value))
    return nil
  end
  if type(value) == "boolean" or type(value) == "number" then return value end
  return nil
end

-- Watchers of an item that changed: codes is the comma list of the codes it can provide.
function api.itemChanged(codes)
  local provided = {}
  for code in codes:gmatch("[^,]+") do provided[code] = true end
  for _, name in ipairs(watchOrder) do
    local watch = watches[name]
    if watch ~= nil and (watch.code == "*" or provided[watch.code]) then
      local ok, message = pcall(watch.callback, watch.code)
      if not ok then B.log("error", "watcher " .. tostring(name) .. " : " .. tostring(message)) end
    end
  end
end

function api.sectionChanged(ref)
  local section = proxy(ref)
  for _, name in ipairs(sectionOrder) do
    local callback = sectionHandlers[name]
    if callback ~= nil then
      local ok, message = pcall(callback, section)
      if not ok then B.log("error", "handler " .. tostring(name) .. " : " .. tostring(message)) end
    end
  end
end

-- One Archipelago event, as JSON (story 54.6).
function api.ap(text)
  applyApEvent(decodeJson(text))
end

function api.archipelagoHandlerCount(kind)
  local list = apHandlers[kind]
  return list and #list or 0
end

-- Runs a snippet as the pack would (tests, and the sandbox check).
function api.run(source)
  return run("snippet.lua", source)
end

return api
`;
