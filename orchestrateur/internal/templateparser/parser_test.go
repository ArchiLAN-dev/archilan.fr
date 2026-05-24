package templateparser_test

import (
	"testing"

	"archilan.fr/orchestrateur/internal/templateparser"
)

// paintTemplate is a representative excerpt of a real generated template.
const paintTemplate = `# Q. What is this file?
# A. This file contains options which allow you to configure your multiworld experience.

# Your name in-game, limited to 16 characters.
name: Player{number}

# Used to describe your yaml.
description: Default Paint Template

game: Paint
requires:
  version: 0.6.7

Paint:
  ################
  # Game Options #
  ################
  progression_balancing:
    # A system that can move progression earlier, to try and prevent the player from getting stuck and bored early.
    #
    # A lower setting means more getting stuck. A higher setting means less getting stuck.
    #
    # You can define additional values between the minimum and maximum values.
    # Minimum value is 0
    # Maximum value is 99
    random: 0
    random-low: 0 # random value weighted towards lower values
    random-high: 0 # random value weighted towards higher values
    normal: 50 # equivalent to 50
    extreme: 0 # equivalent to 99

  accessibility:
    # Set rules for reachability of your items/locations.
    full: 50
    minimal: 0

  logic_percent:
    # Sets the maximum percent similarity required for a check to be in logic.
    # Higher values are more difficult.
    #
    # You can define additional values between the minimum and maximum values.
    # Minimum value is 50
    # Maximum value is 95
    80: 50
    random: 0
    random-low: 0 # random value weighted towards lower values
    random-high: 0 # random value weighted towards higher values
    random-range-50-95: 0 # random value between 50 and 95

  swordless:
    # Removes all swords from the item pool.
    false: 50
    true: 0

  smallkey_shuffle:
    # Control where small keys can be found.
    original_dungeon: 50
    any_world: 0
    own_world: 0
    different_world: 0
`

func ptr(n int) *int { return &n }

func TestParse_includesUniversalOptions(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	find := func(key string) bool {
		for _, o := range opts {
			if o.Key == key {
				return true
			}
		}
		return false
	}
	if !find("progression_balancing") {
		t.Error("progression_balancing should be included")
	}
	if !find("accessibility") {
		t.Error("accessibility should be included")
	}
}

func TestParse_rangeOption(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "logic_percent")
	if opt == nil {
		t.Fatal("logic_percent not found")
	}
	if opt.Type != "range" {
		t.Errorf("type: got %q, want %q", opt.Type, "range")
	}
	if opt.RangeMin == nil || *opt.RangeMin != 50 {
		t.Errorf("RangeMin: got %v, want 50", opt.RangeMin)
	}
	if opt.RangeMax == nil || *opt.RangeMax != 95 {
		t.Errorf("RangeMax: got %v, want 95", opt.RangeMax)
	}
	if opt.DefaultValue != 80 {
		t.Errorf("DefaultValue: got %v, want 80", opt.DefaultValue)
	}
}

func TestParse_toggleOption(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "swordless")
	if opt == nil {
		t.Fatal("swordless not found")
	}
	if opt.Type != "toggle" {
		t.Errorf("type: got %q, want %q", opt.Type, "toggle")
	}
	if opt.DefaultValue != false {
		t.Errorf("DefaultValue: got %v, want false", opt.DefaultValue)
	}
}

func TestParse_choiceOption(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "smallkey_shuffle")
	if opt == nil {
		t.Fatal("smallkey_shuffle not found")
	}
	if opt.Type != "choice" {
		t.Errorf("type: got %q, want %q", opt.Type, "choice")
	}
	if opt.DefaultValue != "original_dungeon" {
		t.Errorf("DefaultValue: got %v, want original_dungeon", opt.DefaultValue)
	}
	if len(opt.ValidValues) != 4 {
		t.Errorf("ValidValues count: got %d, want 4", len(opt.ValidValues))
	}
}

func TestParse_description(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "logic_percent")
	if opt == nil {
		t.Fatal("logic_percent not found")
	}
	if opt.Description == "" {
		t.Error("description should not be empty")
	}
	if contains(opt.Description, "Minimum value is") {
		t.Error("description should not contain 'Minimum value is'")
	}
	if contains(opt.Description, "You can define") {
		t.Error("description should not contain 'You can define'")
	}
}

// quotedBoolTemplate reflects the real Archipelago convention where boolean options
// use YAML-quoted keys ('true'/'false') to prevent the YAML parser from treating
// them as native booleans.
const quotedBoolTemplate = `game: SomeGame
SomeGame:
  skip_cutscenes:
    # Skips all cutscenes.
    'false': 50
    'true': 0

  game_version:
    # Select Red or Blue version.
    red: 0
    blue: 0
    random: 50

  seed_option:
    # Only random values.
    random: 50
    random-low: 0
    random-high: 0
`

func TestParse_quotedBooleans_detectedAsToggle(t *testing.T) {
	opts := templateparser.Parse([]byte(quotedBoolTemplate))
	opt := findOpt(opts, "skip_cutscenes")
	if opt == nil {
		t.Fatal("skip_cutscenes not found")
	}
	if opt.Type != "toggle" {
		t.Errorf("type: got %q, want %q", opt.Type, "toggle")
	}
	if opt.DefaultValue != false {
		t.Errorf("DefaultValue: got %v, want false", opt.DefaultValue)
	}
	if opt.Weights == nil {
		t.Fatal("Weights should be non-nil for toggle option")
	}
	if opt.Weights["false"] != 50 {
		t.Errorf("false weight: got %d, want 50", opt.Weights["false"])
	}
	if opt.Weights["true"] != 0 {
		t.Errorf("true weight: got %d, want 0", opt.Weights["true"])
	}
}

func TestParse_stringKeysWithRandom_detectedAsChoice(t *testing.T) {
	opts := templateparser.Parse([]byte(quotedBoolTemplate))
	opt := findOpt(opts, "game_version")
	if opt == nil {
		t.Fatal("game_version not found")
	}
	if opt.Type != "choice" {
		t.Errorf("type: got %q, want %q", opt.Type, "choice")
	}
	if opt.DefaultValue != "random" {
		t.Errorf("DefaultValue: got %v, want random", opt.DefaultValue)
	}
	if len(opt.ValidValues) != 3 {
		t.Errorf("ValidValues count: got %d, want 3 (red, blue, random)", len(opt.ValidValues))
	}
}

func TestParse_onlyRandomKeys_detectedAsText(t *testing.T) {
	opts := templateparser.Parse([]byte(quotedBoolTemplate))
	opt := findOpt(opts, "seed_option")
	if opt == nil {
		t.Fatal("seed_option not found")
	}
	if opt.Type != "text" {
		t.Errorf("type: got %q, want %q", opt.Type, "text")
	}
	if opt.DefaultValue != nil {
		t.Errorf("DefaultValue: got %v, want nil", opt.DefaultValue)
	}
}

func TestParse_choiceOption_hasWeights(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "smallkey_shuffle")
	if opt == nil {
		t.Fatal("smallkey_shuffle not found")
	}
	if opt.Weights == nil {
		t.Fatal("Weights should be non-nil for choice option")
	}
	if opt.Weights["original_dungeon"] != 50 {
		t.Errorf("original_dungeon weight: got %d, want 50", opt.Weights["original_dungeon"])
	}
	if opt.Weights["any_world"] != 0 {
		t.Errorf("any_world weight: got %d, want 0", opt.Weights["any_world"])
	}
}

func TestParse_toggleOption_hasWeights(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "swordless")
	if opt == nil {
		t.Fatal("swordless not found")
	}
	if opt.Weights == nil {
		t.Fatal("Weights should be non-nil for toggle option")
	}
	if opt.Weights["false"] != 50 {
		t.Errorf("false weight: got %d, want 50", opt.Weights["false"])
	}
	if opt.Weights["true"] != 0 {
		t.Errorf("true weight: got %d, want 0", opt.Weights["true"])
	}
}

func TestParse_rangeOption_hasNoWeights(t *testing.T) {
	opts := templateparser.Parse([]byte(paintTemplate))
	opt := findOpt(opts, "logic_percent")
	if opt == nil {
		t.Fatal("logic_percent not found")
	}
	if opt.Weights != nil {
		t.Errorf("Weights should be nil for range option, got %v", opt.Weights)
	}
}

func TestParse_rangeDefault_fromEquivalentComment(t *testing.T) {
	tmpl := `game: Foo
Foo:
  my_range:
    # Some description.
    # Minimum value is 0
    # Maximum value is 10
    random: 0
    disabled: 0 # equivalent to 0
    normal: 50 # equivalent to 5
    extreme: 0 # equivalent to 10
`
	opts := templateparser.Parse([]byte(tmpl))
	opt := findOpt(opts, "my_range")
	if opt == nil {
		t.Fatal("my_range not found")
	}
	if opt.DefaultValue != 5 {
		t.Errorf("DefaultValue: got %v, want 5 (from 'equivalent to 5')", opt.DefaultValue)
	}
}

func findOpt(opts []templateparser.Option, key string) *templateparser.Option {
	for i := range opts {
		if opts[i].Key == key {
			return &opts[i]
		}
	}
	return nil
}

func contains(s, sub string) bool {
	return len(s) >= len(sub) && (s == sub || len(s) > 0 && containsRune(s, sub))
}

func containsRune(s, sub string) bool {
	for i := range s {
		if i+len(sub) <= len(s) && s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
