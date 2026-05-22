package preflight

import (
	"strings"
	"testing"
)

// ── abbreviateGame ────────────────────────────────────────────────────────────

func TestAbbreviateGame_SingleWord(t *testing.T) {
	if got := abbreviateGame("Celeste"); got != "C" {
		t.Errorf("got %q, want %q", got, "C")
	}
}

func TestAbbreviateGame_SkipsStopWords(t *testing.T) {
	if got := abbreviateGame("The Legend of Zelda"); got != "LZ" {
		t.Errorf("got %q, want %q", got, "LZ")
	}
}

func TestAbbreviateGame_HollowKnight(t *testing.T) {
	if got := abbreviateGame("Hollow Knight"); got != "HK" {
		t.Errorf("got %q, want %q", got, "HK")
	}
}

func TestAbbreviateGame_MaxFourChars(t *testing.T) {
	got := abbreviateGame("Super Mario Odyssey Deluxe Edition")
	if len(got) > 4 {
		t.Errorf("got %q (len %d), want ≤4 chars", got, len(got))
	}
}

func TestAbbreviateGame_EmptyFallback(t *testing.T) {
	if got := abbreviateGame(""); got != "G" {
		t.Errorf("got %q, want %q", got, "G")
	}
}

func TestAbbreviateGame_AllStopWords(t *testing.T) {
	// All stop words → falls back to using all words
	got := abbreviateGame("a the of")
	if got == "" || len(got) > 4 {
		t.Errorf("got %q, want non-empty ≤4 chars", got)
	}
}

// ── sanitizePlayerName ────────────────────────────────────────────────────────

func TestSanitizePlayerName_StripsNonAlphanumeric(t *testing.T) {
	if got := sanitizePlayerName("Alice-1"); got != "Alice1" {
		t.Errorf("got %q, want %q", got, "Alice1")
	}
}

func TestSanitizePlayerName_FallbackWhenEmpty(t *testing.T) {
	if got := sanitizePlayerName("---"); got != "Player" {
		t.Errorf("got %q, want %q", got, "Player")
	}
}

func TestSanitizePlayerName_FallbackOnBlank(t *testing.T) {
	if got := sanitizePlayerName(""); got != "Player" {
		t.Errorf("got %q, want %q", got, "Player")
	}
}

func TestSanitizePlayerName_KeepsAlphanumericIntact(t *testing.T) {
	if got := sanitizePlayerName("Alice42"); got != "Alice42" {
		t.Errorf("got %q, want %q", got, "Alice42")
	}
}

// ── GenerateSlotNames ─────────────────────────────────────────────────────────

func TestGenerateSlotNames_SingleSlot(t *testing.T) {
	names := GenerateSlotNames([]SlotInput{
		{SlotID: "s1", PlayerName: "Alice", ArchipelagoGameName: "Hollow Knight"},
	})
	if got := names["s1"]; got != "Alice_HK1" {
		t.Errorf("got %q, want %q", got, "Alice_HK1")
	}
}

func TestGenerateSlotNames_SamePlayerSameGameIncrements(t *testing.T) {
	names := GenerateSlotNames([]SlotInput{
		{SlotID: "s1", PlayerName: "Alice", ArchipelagoGameName: "Hollow Knight"},
		{SlotID: "s2", PlayerName: "Alice", ArchipelagoGameName: "Hollow Knight"},
	})
	if names["s1"] != "Alice_HK1" {
		t.Errorf("s1: got %q, want %q", names["s1"], "Alice_HK1")
	}
	if names["s2"] != "Alice_HK2" {
		t.Errorf("s2: got %q, want %q", names["s2"], "Alice_HK2")
	}
}

func TestGenerateSlotNames_DifferentPlayersAreUnique(t *testing.T) {
	names := GenerateSlotNames([]SlotInput{
		{SlotID: "s1", PlayerName: "Alice", ArchipelagoGameName: "Hollow Knight"},
		{SlotID: "s2", PlayerName: "Bob", ArchipelagoGameName: "Hollow Knight"},
	})
	if names["s1"] == names["s2"] {
		t.Errorf("expected different names, both got %q", names["s1"])
	}
	for id, name := range names {
		if len(name) > slotMaxLen {
			t.Errorf("slot %s: name %q exceeds %d chars", id, name, slotMaxLen)
		}
	}
}

func TestGenerateSlotNames_NeverExceed16Chars(t *testing.T) {
	slots := make([]SlotInput, 5)
	for i := range slots {
		slots[i] = SlotInput{
			SlotID:              strings.Repeat("x", i+1),
			PlayerName:          "Alexandrina",
			ArchipelagoGameName: "Super Metroid",
		}
	}
	names := GenerateSlotNames(slots)
	for id, name := range names {
		if len(name) > slotMaxLen {
			t.Errorf("slot %s: name %q exceeds %d chars", id, name, slotMaxLen)
		}
	}
}

func TestGenerateSlotNames_1001DuplicatesAllUnique(t *testing.T) {
	const n = 1001
	slots := make([]SlotInput, n)
	for i := range slots {
		slots[i] = SlotInput{
			SlotID:              strings.Repeat("x", i+1),
			PlayerName:          "Alexandrina",
			ArchipelagoGameName: "Super Metroid",
		}
	}
	names := GenerateSlotNames(slots)

	seen := map[string]bool{}
	for id, name := range names {
		if len(name) > slotMaxLen {
			t.Errorf("slot %s: name %q exceeds %d chars", id, name, slotMaxLen)
		}
		if seen[name] {
			t.Errorf("duplicate name %q", name)
		}
		seen[name] = true
	}
	if len(seen) != n {
		t.Errorf("got %d unique names, want %d", len(seen), n)
	}
}

func TestGenerateSlotNames_10SamePlayerAllUnique(t *testing.T) {
	slots := make([]SlotInput, 10)
	for i := range slots {
		slots[i] = SlotInput{
			SlotID:              strings.Repeat("s", i+1),
			PlayerName:          "Alice",
			ArchipelagoGameName: "Hollow Knight",
		}
	}
	names := GenerateSlotNames(slots)
	seen := map[string]bool{}
	for _, name := range names {
		if seen[name] {
			t.Errorf("duplicate name %q", name)
		}
		seen[name] = true
	}
}

func TestGenerateSlotNames_EmptyReturnsEmpty(t *testing.T) {
	names := GenerateSlotNames(nil)
	if len(names) != 0 {
		t.Errorf("expected empty map, got %v", names)
	}
}

// ── HasOptionValue ────────────────────────────────────────────────────────────

func TestHasOptionValue(t *testing.T) {
	cases := []struct {
		name string
		v    any
		want bool
	}{
		{"nil", nil, false},
		{"empty string", "", false},
		{"non-empty string", "standard", true},
		{"zero int", 0, true},
		{"positive int", 42, true},
		{"false bool", false, true},
		{"true bool", true, true},
		{"float", 3.14, true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := HasOptionValue(tc.v); got != tc.want {
				t.Errorf("HasOptionValue(%v) = %v, want %v", tc.v, got, tc.want)
			}
		})
	}
}
