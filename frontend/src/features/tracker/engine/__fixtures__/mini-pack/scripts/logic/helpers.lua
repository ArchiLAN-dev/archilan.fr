local modname = ...

function has(code, amount)
  local count = Tracker:ProviderCountForCode(code)
  amount = tonumber(amount)
  if not amount then return count > 0 end
  return count >= amount
end

function can_cut()
  return has("sword")
end

function count_bombs()
  return Tracker:ProviderCountForCode("bombs")
end

function bow_level()
  if has("bow") then return AccessibilityLevel.Normal end
  return AccessibilityLevel.SequenceBreak
end

function broken_rule()
  error("broken on purpose")
end

return { name = modname }
