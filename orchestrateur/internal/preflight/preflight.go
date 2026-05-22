package preflight

import (
	"fmt"
	"regexp"
	"strings"
	"unicode"
)

const slotMaxLen = 16

var stopWords = map[string]bool{
	"a": true, "an": true, "the": true, "of": true,
	"to": true, "in": true, "for": true, "and": true,
	"or": true, "at": true, "by": true,
}

var nonAlphanumRe = regexp.MustCompile(`[^a-zA-Z0-9\s]`)
var nonAlphanumNoSpaceRe = regexp.MustCompile(`[^a-zA-Z0-9]`)

func abbreviateGame(name string) string {
	cleaned := nonAlphanumRe.ReplaceAllString(name, "")
	words := strings.Fields(cleaned)
	significant := make([]string, 0, len(words))
	for _, w := range words {
		if !stopWords[strings.ToLower(w)] {
			significant = append(significant, w)
		}
	}
	if len(significant) == 0 {
		significant = words
	}
	var abbr strings.Builder
	for _, w := range significant {
		if w != "" {
			abbr.WriteRune(unicode.ToUpper([]rune(w)[0]))
		}
	}
	s := abbr.String()
	if s == "" {
		return "G"
	}
	if len(s) > 4 {
		return s[:4]
	}
	return s
}

func sanitizePlayerName(name string) string {
	s := nonAlphanumNoSpaceRe.ReplaceAllString(name, "")
	if s == "" {
		return "Player"
	}
	return s
}

func buildSlotName(player, abbr string, n int) string {
	counter := fmt.Sprintf("%d", n)
	suffix := "_" + abbr + counter
	if len(suffix) >= slotMaxLen {
		suffix = "_" + counter
	}
	if len(suffix) >= slotMaxLen {
		if len(counter) > slotMaxLen {
			return counter[len(counter)-slotMaxLen:]
		}
		return counter
	}
	maxPlayer := slotMaxLen - len(suffix)
	if maxPlayer < 1 {
		maxPlayer = 1
	}
	p := []rune(player)
	if len(p) > maxPlayer {
		p = p[:maxPlayer]
	}
	return string(p) + suffix
}

// SlotInput carries the data needed to generate a proposed slot name.
type SlotInput struct {
	SlotID              string
	PlayerName          string
	ArchipelagoGameName string
}

// GenerateSlotNames returns a map of slotId → proposed slot name (≤16 chars, unique).
func GenerateSlotNames(slots []SlotInput) map[string]string {
	used := map[string]bool{}
	perPair := map[[2]string]int{}
	result := map[string]string{}

	for _, slot := range slots {
		playerBase := sanitizePlayerName(slot.PlayerName)
		abbr := abbreviateGame(slot.ArchipelagoGameName)
		pair := [2]string{playerBase, abbr}

		perPair[pair]++
		idx := perPair[pair]

		candidate := buildSlotName(playerBase, abbr, idx)
		for used[candidate] {
			idx++
			candidate = buildSlotName(playerBase, abbr, idx)
		}
		used[candidate] = true
		result[slot.SlotID] = candidate
	}
	return result
}

// HasOptionValue mirrors the Python _has_option_value check:
// nil and empty string are considered "no value".
func HasOptionValue(v any) bool {
	if v == nil {
		return false
	}
	if s, ok := v.(string); ok {
		return s != ""
	}
	return true
}