-- Archipelago handlers of the mini pack (stories 54.4 and 54.6), written like the packs in the wild.
ITEMS = { [1] = { "sword", "toggle" }, [2] = { "bow", "progressive" }, [3] = { "bombs", "consumable" } }
LOCATIONS = { [100] = "@Forest/Hut/Chest", [101] = "@Forest/Hut/Pots", [102] = "@Glade/Flower" }
CUR_INDEX = -1
SLOT_DATA = nil
CALLS = {}

local function note(text) CALLS[#CALLS + 1] = text end

local function hintKey()
  if AutoTracker:GetConnectionState("AP") ~= 3 then return nil end
  return string.format("_read_hints_%s_%s", Archipelago.TeamNumber, Archipelago.PlayerNumber)
end

Archipelago:AddClearHandler("clear handler", function(slot_data)
  Tracker.BulkUpdate = true
  SLOT_DATA = slot_data
  CUR_INDEX = -1
  for _, mapping in pairs(ITEMS) do
    local item = Tracker:FindObjectForCode(mapping[1])
    if mapping[2] == "consumable" then item.AcquiredCount = 0 else item.Active = false end
    if mapping[2] == "progressive" then item.CurrentStage = 0 end
  end
  for _, code in pairs(LOCATIONS) do
    local section = Tracker:FindObjectForCode(code)
    section.AvailableChestCount = section.ChestCount
  end
  if slot_data.start_bombs then Tracker:FindObjectForCode("bombs").AcquiredCount = slot_data.start_bombs end
  local key = hintKey()
  Archipelago:SetNotify({ key })
  Archipelago:Get({ key })
  note("clear")
  Tracker.BulkUpdate = false
end)

Archipelago:AddItemHandler("item handler", function(index, item_id, item_name, player)
  if index <= CUR_INDEX then return end
  CUR_INDEX = index
  note(string.format("item %d %s %s", index, tostring(item_name), tostring(player)))
  local mapping = ITEMS[item_id]
  if not mapping then return end
  local item = Tracker:FindObjectForCode(mapping[1])
  if mapping[2] == "toggle" then
    item.Active = true
  elseif mapping[2] == "progressive" then
    if item.Active then item.CurrentStage = item.CurrentStage + 1 else item.Active = true end
  else
    item.AcquiredCount = item.AcquiredCount + item.Increment
  end
end)

Archipelago:AddLocationHandler("location handler", function(location_id, location_name)
  note(string.format("location %d %s", location_id, tostring(location_name)))
  local code = LOCATIONS[location_id]
  if code then Tracker:FindObjectForCode(code).AvailableChestCount = 0 end
end)

local function onStorage(key, value)
  note("storage " .. tostring(key))
  if key ~= hintKey() or type(value) ~= "table" then return end
  for _, hint in ipairs(value) do
    local code = LOCATIONS[hint.location]
    if code and hint.finding_player == Archipelago.PlayerNumber then
      Tracker:FindObjectForCode(code).Highlight = hint.status == 30 and Highlight.Priority or Highlight.Unspecified
    end
  end
end
Archipelago:AddRetrievedHandler("retrieved handler", onStorage)
Archipelago:AddSetReplyHandler("set reply handler", onStorage)
