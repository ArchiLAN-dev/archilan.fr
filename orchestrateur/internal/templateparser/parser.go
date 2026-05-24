package templateparser

import (
	"bufio"
	"bytes"
	"regexp"
	"strconv"
	"strings"
)

// Option is a parsed game-specific option from an Archipelago YAML template.
type Option struct {
	Key          string
	Description  string
	Type         string // range | choice | toggle | text
	DefaultValue any
	ValidValues  []string
	Weights      map[string]int // non-nil for choice and toggle options
	RangeMin     *int
	RangeMax     *int
}

var topLevelKeys = map[string]bool{
	"name": true, "description": true, "game": true,
	"requires": true, "quantity": true,
}

var (
	rangeMinRe   = regexp.MustCompile(`Minimum value is (\d+)`)
	rangeMaxRe   = regexp.MustCompile(`Maximum value is (\d+)`)
	equivalentRe = regexp.MustCompile(`equivalent to (-?\d+)`)
)

// Parse extracts all game options from an Archipelago YAML template, including
// universal options (progression_balancing, accessibility, etc.).
// Top-level keys (name, game, description, requires) are excluded.
func Parse(data []byte) []Option {
	scanner := bufio.NewScanner(bytes.NewReader(data))

	var result []Option
	inGameSection := false
	var curKey string
	var curComments []string
	var curValues []string

	flush := func() {
		if curKey == "" {
			return
		}
		result = append(result, buildOption(curKey, curComments, curValues))
		curKey = ""
		curComments = nil
		curValues = nil
	}

	for scanner.Scan() {
		raw := scanner.Text()
		trimmed := strings.TrimLeft(raw, " \t")
		if trimmed == "" {
			continue
		}
		indent := len(raw) - len(trimmed)

		switch {
		case indent == 0:
			key := strings.TrimSuffix(trimmed, ":")
			if strings.HasSuffix(trimmed, ":") && !topLevelKeys[key] {
				flush()
				inGameSection = true
			} else if inGameSection {
				flush()
				inGameSection = false
			}

		case !inGameSection:
			// skip

		case indent == 2:
			if strings.HasPrefix(trimmed, "#") {
				// Group separator (####, # Title #, etc.) — reset
				flush()
			} else if strings.HasSuffix(trimmed, ":") {
				flush()
				curKey = strings.TrimSuffix(trimmed, ":")
			}

		case indent >= 4 && curKey != "":
			if strings.HasPrefix(trimmed, "#") {
				comment := strings.TrimPrefix(trimmed, "# ")
				comment = strings.TrimPrefix(comment, "#")
				curComments = append(curComments, comment)
			} else {
				curValues = append(curValues, trimmed)
			}
		}
	}

	flush()
	return result
}

func buildOption(key string, comments []string, values []string) Option {
	fullText := strings.Join(comments, "\n")

	var rangeMin, rangeMax *int
	if m := rangeMinRe.FindStringSubmatch(fullText); m != nil {
		v, _ := strconv.Atoi(m[1])
		rangeMin = &v
	}
	if m := rangeMaxRe.FindStringSubmatch(fullText); m != nil {
		v, _ := strconv.Atoi(m[1])
		rangeMax = &v
	}

	optType, defaultVal, validVals, weights := inferType(values, rangeMin, rangeMax)

	opt := Option{
		Key:          key,
		Description:  buildDescription(comments),
		Type:         optType,
		DefaultValue: defaultVal,
		ValidValues:  validVals,
		Weights:      weights,
	}
	if rangeMin != nil {
		opt.RangeMin = rangeMin
		opt.RangeMax = rangeMax
	}
	return opt
}

func buildDescription(comments []string) string {
	var lines []string
	for _, c := range comments {
		stripped := strings.TrimSpace(c)
		if strings.HasPrefix(stripped, "You can define") ||
			strings.HasPrefix(stripped, "Minimum value is") ||
			strings.HasPrefix(stripped, "Maximum value is") {
			continue
		}
		lines = append(lines, c)
	}
	for len(lines) > 0 && strings.TrimSpace(lines[0]) == "" {
		lines = lines[1:]
	}
	for len(lines) > 0 && strings.TrimSpace(lines[len(lines)-1]) == "" {
		lines = lines[:len(lines)-1]
	}
	return strings.Join(lines, "\n")
}

type valuePair struct {
	key    string
	weight int
	equiv  *int
}

func stripSingleQuotes(s string) string {
	if len(s) >= 2 && s[0] == '\'' && s[len(s)-1] == '\'' {
		return s[1 : len(s)-1]
	}
	return s
}

func parseValueLines(values []string) []valuePair {
	var pairs []valuePair
	for _, v := range values {
		parts := strings.SplitN(v, " #", 2)
		main := strings.TrimSpace(parts[0])
		var equiv *int
		if len(parts) == 2 {
			if m := equivalentRe.FindStringSubmatch(parts[1]); m != nil {
				n, _ := strconv.Atoi(m[1])
				equiv = &n
			}
		}
		colonIdx := strings.LastIndex(main, ":")
		if colonIdx < 0 {
			continue
		}
		k := stripSingleQuotes(strings.TrimSpace(main[:colonIdx]))
		w, err := strconv.Atoi(strings.TrimSpace(main[colonIdx+1:]))
		if err != nil {
			continue
		}
		pairs = append(pairs, valuePair{k, w, equiv})
	}
	return pairs
}

func inferType(values []string, rangeMin, rangeMax *int) (string, any, []string, map[string]int) {
	pairs := parseValueLines(values)
	if len(pairs) == 0 {
		return "text", nil, nil, nil
	}

	// Explicit range bounds from comments → always range regardless of keys.
	if rangeMin != nil {
		return "range", rangeDefault(pairs), nil, nil
	}

	// Partition into random* and concrete keys.
	var nonRandom []valuePair
	for _, p := range pairs {
		if p.key != "random" && !strings.HasPrefix(p.key, "random-") {
			nonRandom = append(nonRandom, p)
		}
	}

	// All keys are random* → free-form seed / untyped field.
	if len(nonRandom) == 0 {
		return "text", nil, nil, nil
	}

	// Range: all non-random keys are integers (numeric weighted range).
	allInts := true
	for _, p := range nonRandom {
		if _, err := strconv.Atoi(p.key); err != nil {
			allInts = false
			break
		}
	}
	if allInts {
		return "range", rangeDefault(pairs), nil, nil
	}

	// Toggle: exactly true + false among non-random keys.
	keySet := map[string]bool{}
	for _, p := range nonRandom {
		keySet[p.key] = true
	}
	if keySet["true"] && keySet["false"] && len(keySet) == 2 && len(pairs) == len(nonRandom) {
		var def bool
		weights := make(map[string]int, 2)
		for _, p := range nonRandom {
			weights[p.key] = p.weight
			if p.key == "true" && p.weight > 0 {
				def = true
			}
		}
		return "toggle", def, nil, weights
	}

	// Choice: string keys (may include random as a selectable option).
	var validVals []string
	var def any
	best := -1
	weights := make(map[string]int, len(pairs))
	for _, p := range pairs {
		validVals = append(validVals, p.key)
		weights[p.key] = p.weight
		if p.weight > best {
			best = p.weight
			def = p.key
		}
	}
	return "choice", def, validVals, weights
}

func rangeDefault(pairs []valuePair) any {
	// Prefer a non-random key with an "equivalent to N" comment.
	for _, p := range pairs {
		if p.key == "random" || strings.HasPrefix(p.key, "random-") {
			continue
		}
		if p.equiv != nil && p.weight > 0 {
			return *p.equiv
		}
	}
	// Fall back to integer key with highest weight.
	var def any
	best := -1
	for _, p := range pairs {
		if p.key == "random" || strings.HasPrefix(p.key, "random-") {
			continue
		}
		if _, err := strconv.Atoi(p.key); err == nil && p.weight > best {
			best = p.weight
			v, _ := strconv.Atoi(p.key)
			def = v
		}
	}
	return def
}
