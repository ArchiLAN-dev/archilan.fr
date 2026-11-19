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
local budgets = __pt_budgets
__pt_budgets = nil

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
-- Story 54.11: a pack function that calls itself without end (Twilight Princess: has("lantern") -> hasAll -> has)
-- ends in a stack overflow in PopTracker too, but natively in a blink; here, 90 000 levels deep, it takes seconds,
-- for each rule that meets it. Every 100 000 instructions, a call chain deeper than MAX_CALL_DEPTH fails the same
-- way, at once. No real rule goes that deep. Hooks belong to a Lua thread and each call from the engine may run in
-- its own: guardDepth() sets it at every entry (the api functions, wrapped at the end).
-- Story 54.13: the same hook holds each call to its time budget, so an endless loop fails instead of holding the
-- Worker. Each new coroutine gets the hook too: hooks belong to a thread.
local MAX_CALL_DEPTH = 1000
local CALL_BUDGET = tonumber(budgets.call) or 8
local FRAME_BUDGET = tonumber(budgets.frame) or 1
local BUDGET_MESSAGE = "temps de calcul dépassé"
local guardDepth, startBudget
do
  local getinfo, sethook, clock = debug.getinfo, debug.sethook, os.clock
  local deadline = math.huge
  local function hook()
    if clock() > deadline then
      error(BUDGET_MESSAGE .. ", comme une boucle sans fin", 2)
    end
    if getinfo(MAX_CALL_DEPTH, "l") ~= nil then
      error("récursion sans fin (plus de " .. MAX_CALL_DEPTH .. " appels imbriqués), comme un débordement de pile", 2)
    end
  end
  startBudget = function(seconds) deadline = clock() + seconds end
  guardDepth = function()
    startBudget(CALL_BUDGET)
    sethook(hook, "", 100000)
  end
  local cocreate, resume = coroutine.create, coroutine.resume
  coroutine.create = function(f)
    local co = cocreate(f)
    sethook(co, hook, "", 100000)
    return co
  end
  local function unwrap(ok, ...)
    if not ok then error((...), 0) end
    return ...
  end
  coroutine.wrap = function(f)
    local co = coroutine.create(f)
    return function(...) return unwrap(resume(co, ...)) end
  end
end
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

-- Story 54.8: PopTracker's own API (src/core/tracker.cpp, scripthost.cpp, doc/PACKS.md). A name in it that the site
-- lacks is reported as unsupported; any other name is nil, as in PopTracker, so the pack's own mistake (Spyro 3 calls
-- Tracker:AddMemoryWatch) fails the way it fails there instead of being blamed on the site.
local POPTRACKER_API = {
  Tracker = { AddItems = true, AddLocations = true, AddMaps = true, AddLayouts = true, AddClasses = true,
    ProviderCountForCode = true, FindObjectForCode = true, UiHint = true, OpenLink = true },
  ScriptHost = { LoadScript = true, AddMemoryWatch = true, RemoveMemoryWatch = true, AddWatchForCode = true,
    RemoveWatchForCode = true, CreateLuaItem = true, AddVariableWatch = true, RemoveVariableWatch = true,
    AddOnFrameHandler = true, RemoveOnFrameHandler = true, AddOnLocationSectionChangedHandler = true,
    RemoveOnLocationSectionChangedHandler = true, RemoveOnLocationSectionHandler = true, RunScriptAsync = true,
    RunStringAsync = true, AsyncProgress = true },
  Archipelago = { AddClearHandler = true, AddItemHandler = true, AddLocationHandler = true, AddScoutHandler = true,
    AddBouncedHandler = true, AddRetrievedHandler = true, AddSetReplyHandler = true, SetNotify = true, Get = true,
    LocationChecks = true, LocationScouts = true, StatusUpdate = true, GetPlayerAlias = true, GetPlayerGame = true,
    GetItemName = true, GetLocationName = true },
}

local function missing(owner, name)
  if POPTRACKER_API[owner][name] then return unsupported(owner, name) end
  return nil
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
-- Lua items are defined with ScriptHost below; Tracker:FindObjectForCode needs them.
local findLuaItem
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
-- Story 54.11: counts are kept on this side until the engine says the state changed (api.resetCounts). A pack that
-- walks a graph of regions in Lua (Twilight Princess) asks for the same few hundred codes millions of times, and
-- each question that crosses to the engine costs far more here than in PopTracker.
local countCache = {}
function tracker:ProviderCountForCode(code)
  if type(code) ~= "string" then return 0 end
  local cached = countCache[code]
  if cached ~= nil then return cached end
  local count = B.count(code)
  countCache[code] = count
  return count
end
function tracker:FindObjectForCode(code)
  if type(code) ~= "string" then return nil end
  local ref = B.find(code)
  if ref ~= nil then return proxy(ref) end
  return findLuaItem(code)
end
-- Story 54.11: the site opens no link from a pack; PopTracker returns whether the address is valid.
function tracker:OpenLink(url)
  return type(url) == "string" and url:match("^https?://[^%s]+$") ~= nil
end
-- Story 54.11: the hints go to the screen with the next state; it follows ActivateTab (see TrackerView.activeTab).
function tracker:UiHint(name, value)
  B.uiHint(tostring(name), value == nil and "" or tostring(value))
end
Tracker = setmetatable(tracker, {
  __index = function(_, key)
    if key == "BulkUpdate" or key == "AllowDeferredLogicUpdate" then return B.flag(key) end
    return missing("Tracker", key)
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
-- Story 54.11, as PopTracker: adding a watch under a name already used replaces it and moves it to the end.
function scriptHost:AddWatchForCode(name, code, callback)
  for index, existing in ipairs(watchOrder) do
    if existing == name then table.remove(watchOrder, index) break end
  end
  watches[name] = nil
  if code == nil or code == "" or type(callback) ~= "function" then return "" end
  watchOrder[#watchOrder + 1] = name
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

-- Lua items (story 54.8): items whose state and behaviour the pack writes in Lua. Their state stays here; the engine
-- reads what to draw (name, icon, badge, overlay) and asks which codes they provide.
local luaItems = {}
local luaItemOrder = {}
local luaItemMethods = {}
local LUA_ITEM_DRAWN = { Name = true, Icon = true, IconMods = true, BadgeText = true, PotentialCodes = true }

local function luaItemData(item) return rawget(item, "__data") end
function luaItemMethods:SetOverlay(text)
  luaItemData(self).__overlay = text == nil and "" or tostring(text)
  B.luaItemChanged(rawget(self, "__id"))
end
function luaItemMethods:SetOverlayBackground() end
function luaItemMethods:SetOverlayColor() end
function luaItemMethods:SetOverlayFontSize() end
function luaItemMethods:SetOverlayAlign() end
-- Story 54.11, as PopTracker: the property store of Get and Set is .ItemState.
local function itemStateOf(data)
  if type(data.ItemState) ~= "table" then data.ItemState = {} end
  return data.ItemState
end
function luaItemMethods:Get(key) return itemStateOf(luaItemData(self))[key] end
function luaItemMethods:Set(key, value)
  local data = luaItemData(self)
  local store = itemStateOf(data)
  local before = store[key]
  store[key] = value
  if before == value then return end
  if type(data.PropertyChangedFunc) == "function" then
    local ok, message = pcall(data.PropertyChangedFunc, self, key, value)
    if not ok then B.log("error", "LuaItem PropertyChangedFunc : " .. tostring(message)) end
  end
  -- Story 54.11: as PopTracker, a property set is a change of the item (logic, watchers).
  B.luaItemChanged(rawget(self, "__id"))
end

function scriptHost:CreateLuaItem()
  local id = B.luaItemCreate()
  -- Owner: an empty table, as PopTracker gives for compatibility (story 54.11).
  local data = { Type = "custom", Name = "", Owner = {}, __overlay = "" }
  local item = setmetatable({ __id = id, __data = data }, {
    __index = function(_, key)
      local method = luaItemMethods[key]
      if method ~= nil then return method end
      return data[key]
    end,
    __newindex = function(_, key, value)
      data[key] = value
      if LUA_ITEM_DRAWN[key] then B.luaItemChanged(id) end
    end,
  })
  luaItems[id] = item
  luaItemOrder[#luaItemOrder + 1] = id
  return item
end

-- PotentialCodes, when set, is the list of codes the item can provide; otherwise CanProvideCodeFunc decides.
local function luaItemCanProvide(item, code)
  local data = luaItemData(item)
  local codes = data.PotentialCodes
  if codes ~= nil then
    for _, candidate in pairs(codes) do
      if candidate == code then return true end
    end
    return false
  end
  if type(data.CanProvideCodeFunc) ~= "function" then return false end
  local ok, value = pcall(data.CanProvideCodeFunc, item, code)
  return ok and value == true
end

function findLuaItem(code)
  for _, id in ipairs(luaItemOrder) do
    local item = luaItems[id]
    if luaItemCanProvide(item, code) then return item end
  end
  return nil
end

-- Async scripts (story 54.8): PopTracker runs them in another thread with only arg and return; here they wait for
-- the end of the current logic pass, run in their own environment, and their callback follows.
local asyncQueue = {}
local function queueAsync(name, source, arg, complete)
  asyncQueue[#asyncQueue + 1] = { name = name, source = source, arg = arg, complete = complete }
  return {}
end
function scriptHost:RunScriptAsync(file, arg, complete)
  local source = type(file) == "string" and B.readScript(file) or nil
  if source == nil then error("Could not load script!", 2) end
  return queueAsync(file, source, arg, complete)
end
function scriptHost:RunStringAsync(source, arg, complete)
  if type(source) ~= "string" then error("RunStringAsync: script invalide", 2) end
  return queueAsync("string", source, arg, complete)
end
function scriptHost:AsyncProgress() end

local function deepCopy(value)
  if type(value) ~= "table" then return value end
  local copy = {}
  for key, inner in pairs(value) do copy[deepCopy(key)] = deepCopy(inner) end
  return copy
end

local function asyncEnvironment(arg)
  local env = {
    string = string, table = table, math = math, utf8 = utf8, os = os,
    pairs = pairs, ipairs = ipairs, next = next, type = type, tostring = tostring, tonumber = tonumber,
    select = select, error = error, pcall = pcall, xpcall = xpcall, assert = assert,
    setmetatable = setmetatable, getmetatable = getmetatable,
    rawget = rawget, rawset = rawset, rawequal = rawequal, rawlen = rawlen,
    print = print, load = load,
    ScriptHost = { AsyncProgress = function() end },
    arg = deepCopy(arg),
  }
  env._G = env
  local modules = {}
  env.require = function(name)
    if type(name) ~= "string" then error("require: nom invalide", 2) end
    if modules[name] ~= nil then return modules[name] end
    local path = name:gsub("%.", "/")
    for _, candidate in ipairs({ "scripts/" .. path .. ".lua", "scripts/" .. path .. "/init.lua", path .. ".lua", path .. "/init.lua" }) do
      local source = B.readScript(candidate)
      if source ~= nil then
        local chunk, message = rawload(source, "@" .. candidate, "t", env)
        if not chunk then error(message, 0) end
        local result = chunk(name, candidate)
        if result == nil then result = true end
        modules[name] = result
        return result
      end
    end
    error("module '" .. name .. "' introuvable dans le pack", 2)
  end
  return env
end
-- Story 54.11: frame handlers, called by the engine on a clock (api.frame) with the seconds since the last call.
local frameHandlers = {}
local frameOrder = {}
function scriptHost:AddOnFrameHandler(name, callback)
  if type(callback) ~= "function" then return "" end
  if frameHandlers[name] == nil then frameOrder[#frameOrder + 1] = name end
  frameHandlers[name] = callback
  return name
end
function scriptHost:RemoveOnFrameHandler(name)
  if frameHandlers[name] == nil then return false end
  frameHandlers[name] = nil
  for index, existing in ipairs(frameOrder) do
    if existing == name then table.remove(frameOrder, index) break end
  end
  return true
end
for _, name in ipairs({ "AddMemoryWatch", "RemoveMemoryWatch", "AddVariableWatch", "RemoveVariableWatch" }) do
  scriptHost[name] = memoryOnly("ScriptHost", name)
end
ScriptHost = setmetatable(scriptHost, { __index = function(_, key) return missing("ScriptHost", key) end })

-- AutoTracker, Archipelago --------------------------------------------------------
local autoTracker = {}
AutoTracker = setmetatable(autoTracker, { __index = function(_, key) return memoryOnly("AutoTracker", key) end })

-- Archipelago (story 54.6): the page holds the connection, the engine forwards its events here. PopTracker's
-- connection states: 0 disabled (manual tracking, never connected), 1 disconnected, 3 connected (story 54.11).
local apConnection = 0
autoTracker.GetConnectionState = function(self, name)
  if name == "AP" then return apConnection end
  return 0
end

local apHandlers = {}
local archipelago = { PlayerNumber = -1, TeamNumber = -1, CheckedLocations = {}, MissingLocations = {},
  ClientStatus = { UNKNOWN = 0, CONNECTED = 5, READY = 10, PLAYING = 20, GOAL = 30 } }
-- Story 54.11: the checked ids as a set, so a location the room repeats is listed once and leaves MissingLocations.
local apChecked = {}
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
Archipelago = setmetatable(archipelago, { __index = function(_, key) return missing("Archipelago", key) end })

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
    archipelago.CheckedLocations = {}
    apChecked = {}
    for _, id in ipairs(event.checked or {}) do
      if not apChecked[id] then
        apChecked[id] = true
        archipelago.CheckedLocations[#archipelago.CheckedLocations + 1] = id
      end
    end
    archipelago.MissingLocations = {}
    for _, id in ipairs(event.missing or {}) do
      if not apChecked[id] then archipelago.MissingLocations[#archipelago.MissingLocations + 1] = id end
    end
    apConnection = 3
  elseif kind == "disconnected" then
    apConnection = 1
  elseif kind == "clear" then
    callAll("Clear", event.slotData or {})
  elseif kind == "items" then
    for _, item in ipairs(event.items or {}) do
      callAll("Item", item.index, item.item, B.apName("item", item.item, ""), item.player)
    end
  elseif kind == "locations" then
    local checked = archipelago.CheckedLocations
    for _, id in ipairs(event.locations or {}) do
      if not apChecked[id] then
        apChecked[id] = true
        checked[#checked + 1] = id
        local missing = archipelago.MissingLocations
        for index = #missing, 1, -1 do
          if missing[index] == id then table.remove(missing, index) end
        end
      end
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
-- An image and its mods travel as one string, path and mods separated by a line break (story 54.8).
function ImageReference:FromImageReference(original, mod)
  local path, mods = tostring(original):match("^([^\n]*)\n?(.*)$")
  if mod == nil or mod == "" then return tostring(original) end
  if mods == "" then return path .. "\n" .. tostring(mod) end
  return path .. "\n" .. mods .. "," .. tostring(mod)
end

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
-- Story 54.11, as PopTracker: a "*" watch gets the item's codes (", " between them), another one its own code.
function api.itemChanged(codes)
  local provided, list = {}, {}
  for code in codes:gmatch("[^,]+") do
    provided[code] = true
    list[#list + 1] = code
  end
  local all = table.concat(list, ", ")
  for _, name in ipairs({ table.unpack(watchOrder) }) do
    local watch = watches[name]
    if watch ~= nil and (watch.code == "*" or provided[watch.code]) then
      local ok, message = pcall(watch.callback, watch.code == "*" and all or watch.code)
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

-- Lua items (story 54.8) -------------------------------------------------------
local function asText(value)
  if value == nil then return "" end
  return tostring(value)
end

-- What a Lua item provides of a code: the sum over the Lua items that can provide it.
-- Story 54.11: the Lua item a layout's code names (FindObjectForCode), for an item known by CanProvideCodeFunc only.
function api.luaItemFor(code)
  local item = findLuaItem(code)
  if item == nil then return nil end
  return rawget(item, "__id")
end

function api.frameHandlerCount()
  return #frameOrder
end

function api.frame(elapsed)
  for _, name in ipairs({ table.unpack(frameOrder) }) do
    local callback = frameHandlers[name]
    if callback ~= nil then
      -- Story 54.13: a handler runs every 100 ms: it gets a short budget, and one that blows it is dropped.
      startBudget(FRAME_BUDGET)
      local ok, message = pcall(callback, elapsed)
      if not ok then
        B.log("error", "frame handler " .. tostring(name) .. " : " .. tostring(message))
        if find(tostring(message), BUDGET_MESSAGE, 1, true) then scriptHost:RemoveOnFrameHandler(name) end
      end
    end
  end
end

function api.resetCounts()
  countCache = {}
end

function api.luaProvides(code)
  local total = 0
  for _, id in ipairs(luaItemOrder) do
    local item = luaItems[id]
    if luaItemCanProvide(item, code) then
      local provides = luaItemData(item).ProvidesCodeFunc
      if type(provides) == "function" then
        local ok, value = pcall(provides, item, code)
        if not ok then
          B.log("error", "LuaItem ProvidesCodeFunc : " .. tostring(value))
        elseif type(value) == "number" then
          total = total + value
        elseif value == true then
          total = total + 1
        end
      end
    end
  end
  return total
end

-- name, icon, badge, overlay and the codes (comma list), separated by \31.
function api.luaItemView(id)
  local item = luaItems[id]
  if item == nil then return nil end
  local data = luaItemData(item)
  local codes = {}
  if type(data.PotentialCodes) == "table" then
    for _, code in pairs(data.PotentialCodes) do codes[#codes + 1] = tostring(code) end
  end
  -- Story 54.11: IconMods go on top of the mods the Icon carries.
  local icon = asText(data.Icon)
  local extra = asText(data.IconMods)
  if icon ~= "" and extra ~= "" then
    local path, mods = icon:match("^([^\n]*)\n?(.*)$")
    icon = path .. "\n" .. (mods == "" and extra or mods .. "," .. extra)
  end
  return concat({ asText(data.Name), icon, asText(data.BadgeText), data.__overlay, concat(codes, ",") }, "\31")
end

function api.luaItemClick(id, button)
  local item = luaItems[id]
  if item == nil then return false end
  local key = button == "right" and "OnRightClickFunc" or button == "middle" and "OnMiddleClickFunc" or "OnLeftClickFunc"
  local data = luaItemData(item)
  -- Story 54.11: IgnoreUserInput, as PopTracker, leaves the item as it is on a click.
  if data.IgnoreUserInput then return false end
  local callback = data[key]
  if type(callback) ~= "function" then return false end
  local ok, message = pcall(callback, item)
  if not ok then B.log("error", "LuaItem " .. key .. " : " .. tostring(message)) end
  return true
end

-- Runs the async scripts queued so far, then their callbacks; returns how many ran.
function api.runAsync()
  local queue = asyncQueue
  asyncQueue = {}
  for _, task in ipairs(queue) do
    local chunk, message = rawload(task.source, "@" .. task.name, "t", asyncEnvironment(task.arg))
    if not chunk then
      B.log("error", "RunScriptAsync " .. task.name .. " : " .. tostring(message))
    else
      local ok, result = pcall(chunk)
      if not ok then
        B.log("error", "RunScriptAsync " .. task.name .. " : " .. tostring(result))
      elseif type(task.complete) == "function" then
        local done, failure = pcall(task.complete, result)
        if not done then B.log("error", "RunScriptAsync " .. task.name .. " (callback) : " .. tostring(failure)) end
      end
    end
  end
  return #queue
end

function api.pendingAsync()
  return #asyncQueue
end

function api.archipelagoHandlerCount(kind)
  local list = apHandlers[kind]
  return list and #list or 0
end

-- Runs a snippet as the pack would (tests, and the sandbox check).
function api.run(source)
  return run("snippet.lua", source)
end

for name, f in pairs(api) do
  api[name] = function(...)
    guardDepth()
    return f(...)
  end
end

return api
`;
