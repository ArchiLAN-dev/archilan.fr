package service

import (
	"strings"
	"testing"
)

const luigiTemplate = `game: Luigi's Mansion
Luigi's Mansion:
  toadsanity:
    false: 50
    true: 0
  rank_requirement:
    rank_h: 50
    rank_g: 0
    rank_f: 0
  progression_balancing:
    # Minimum value is 0
    # Maximum value is 99
    normal: 50 # equivalent to 50
`

func TestApworldOptionKeys_knownKeys(t *testing.T) {
	keys := apworldOptionKeys([]byte(luigiTemplate))

	for _, want := range []string{"toadsanity", "rank_requirement", "progression_balancing"} {
		if !keys[want] {
			t.Errorf("expected %q to be a valid key", want)
		}
	}
}

func TestApworldOptionKeys_unknownKey(t *testing.T) {
	keys := apworldOptionKeys([]byte(luigiTemplate))

	if keys["toadanity"] {
		t.Error("typo 'toadanity' should not be a valid key")
	}
	if keys[""] {
		t.Error("empty string should not be a valid key")
	}
}

func TestApworldOptionKeys_emptyTemplate(t *testing.T) {
	keys := apworldOptionKeys([]byte{})
	if len(keys) != 0 {
		t.Errorf("expected empty key set, got %d keys", len(keys))
	}
}

func TestBuildPlayerYaml_scalarValues(t *testing.T) {
	out, err := buildPlayerYaml("Jean", "Luigi's Mansion", map[string]any{
		"toadsanity": true,
	})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "name: Jean") {
		t.Errorf("missing name field: %s", out)
	}
	if !strings.Contains(out, "toadsanity: true") {
		t.Errorf("missing toadsanity: %s", out)
	}
}

func TestBuildPlayerYaml_weightedValues(t *testing.T) {
	out, err := buildPlayerYaml("Jean", "Luigi's Mansion", map[string]any{
		"rank_requirement": map[string]any{
			"rank_h": float64(70),
			"rank_f": float64(30),
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "rank_h: 70") {
		t.Errorf("missing rank_h weight: %s", out)
	}
	if !strings.Contains(out, "rank_f: 30") {
		t.Errorf("missing rank_f weight: %s", out)
	}
}

func TestBuildPlayerYaml_emptyValues(t *testing.T) {
	out, err := buildPlayerYaml("Jean", "Luigi's Mansion", nil)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "name: Jean") {
		t.Errorf("missing name field: %s", out)
	}
	if !strings.Contains(out, "game: Luigi") {
		t.Errorf("missing game field: %s", out)
	}
}
