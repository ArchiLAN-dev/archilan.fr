local helpers = require("logic.helpers")
VARIANT = Tracker.ActiveVariantUID
HELPERS_NAME = helpers.name

Tracker:AddItems("items/items.jsonc")
Tracker:AddMaps("maps/maps.json")
Tracker:AddLocations("locations/locations.jsonc")
Tracker:AddLayouts("layouts/tracker.json")

ScriptHost:AddWatchForCode("sword watch", "sword", function(code)
  local counter = Tracker:FindObjectForCode("watch_counter")
  counter.AcquiredCount = counter.AcquiredCount + 1
end)

if PopVersion and PopVersion >= "0.18.0" then
  ScriptHost:LoadScript("scripts/archipelago.lua")
end
