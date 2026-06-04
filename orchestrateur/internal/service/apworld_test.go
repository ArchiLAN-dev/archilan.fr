package service

import (
	"archive/zip"
	"bytes"
	"testing"
)

// buildZip creates an in-memory ZIP with the given files (path → content).
func buildZip(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	for name, content := range files {
		f, err := w.Create(name)
		if err != nil {
			t.Fatalf("zip.Create(%q): %v", name, err)
		}
		if _, err = f.Write([]byte(content)); err != nil {
			t.Fatalf("zip.Write(%q): %v", name, err)
		}
	}
	if err := w.Close(); err != nil {
		t.Fatalf("zip.Close: %v", err)
	}
	return buf.Bytes()
}

func TestExtractGameFromZip_archipelagoJson(t *testing.T) {
	data := buildZip(t, map[string]string{
		"archeliagungame/archipelago.json": `{"game":"ArchipelaGun","minimum_ap_version":"0.5.0"}`,
		"archeliagungame/__init__.py":      "",
	})

	got := extractGameFromZip(data)
	if got != "ArchipelaGun" {
		t.Errorf("expected %q, got %q", "ArchipelaGun", got)
	}
}

func TestExtractGameFromZip_nameFieldFallback(t *testing.T) {
	data := buildZip(t, map[string]string{
		"mygame/archipelago.json": `{"name":"My Game"}`,
	})

	got := extractGameFromZip(data)
	if got != "My Game" {
		t.Errorf("expected %q, got %q", "My Game", got)
	}
}

func TestExtractGameFromZip_noArchipelagoJson(t *testing.T) {
	data := buildZip(t, map[string]string{
		"bfbb/__init__.py": `game = "Battle for Bikini Bottom"`,
	})

	got := extractGameFromZip(data)
	if got != "bfbb" {
		t.Errorf("expected folder name %q, got %q", "bfbb", got)
	}
}

func TestExtractGameFromZip_invalidZip(t *testing.T) {
	got := extractGameFromZip([]byte("not a zip"))
	if got != "" {
		t.Errorf("expected empty string for invalid zip, got %q", got)
	}
}

func TestExtractGameFromZip_emptyData(t *testing.T) {
	got := extractGameFromZip([]byte{})
	if got != "" {
		t.Errorf("expected empty string for empty data, got %q", got)
	}
}

func TestExtractApworldGame_emptyYaml(t *testing.T) {
	got := extractApworldGame([]byte{})
	if got != "" {
		t.Errorf("expected empty string for empty yaml, got %q", got)
	}
}

func TestExtractApworldGame_present(t *testing.T) {
	yaml := []byte("game: Battle for Bikini Bottom\nBattle for Bikini Bottom:\n  option: 1\n")
	got := extractApworldGame(yaml)
	if got != "Battle for Bikini Bottom" {
		t.Errorf("expected %q, got %q", "Battle for Bikini Bottom", got)
	}
}
