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
function autoTracker:GetConnectionState(name) return 0 end
AutoTracker = setmetatable(autoTracker, { __index = function(_, key) return memoryOnly("AutoTracker", key) end })

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
function archipelago:Get(keys) return false end
function archipelago:SetNotify(keys) return false end
function archipelago:LocationChecks(locations) return false end
function archipelago:LocationScouts(locations, sendAsHint) return false end
function archipelago:StatusUpdate(status) return false end
Archipelago = setmetatable(archipelago, { __index = function(_, key) return unsupported("Archipelago", key) end })

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
