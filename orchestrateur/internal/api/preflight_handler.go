package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"github.com/go-chi/chi/v5"

	"archilan.fr/orchestrateur/internal/preflight"
)

// handlePreflight godoc
// @Summary     Validate slots before generation
// @Description Validates slot data (playerYaml, required options) and returns proposed slot names.
// @Description Stateless — does not create or modify any session record.
// @Tags        sessions
// @Accept      json
// @Produce     json
// @Param       sessionId path     string          true "Session ID (informational only)"
// @Param       body      body     PreflightRequest true "Slot list"
// @Success     200       {object} PreflightResponse
// @Failure     400       {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/preflight [post]
func handlePreflight() http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		_ = chi.URLParam(r, "sessionId") // accepted but unused — endpoint is stateless

		var req PreflightRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "invalid request body")
			return
		}

		valid := true
		perSlotErrors := make(map[string][]string, len(req.Slots))
		namingInputs := make([]preflight.SlotInput, 0, len(req.Slots))

		for _, slot := range req.Slots {
			var errs []string
			var gameName string

			if slot.ApworldStorageKey != "" {
				// Custom apworld slot
				if strings.TrimSpace(slot.PlayerYaml) == "" {
					errs = append(errs, "playerYaml est requis et ne peut pas être vide.")
				}
				gameName = "Custom"
			} else {
				// Bundled game slot
				if strings.TrimSpace(slot.ArchipelagoGameName) == "" {
					errs = append(errs, "Ce jeu n'a pas de nom Archipelago configuré.")
				}
				for _, opt := range slot.Options {
					if opt.Required && !preflight.HasOptionValue(opt.CurrentValue) && !preflight.HasOptionValue(opt.DefaultValue) {
						key := opt.Key
						if key == "" {
							key = "?"
						}
						errs = append(errs, fmt.Sprintf("L'option '%s' est requise mais n'a pas de valeur.", key))
					}
				}
				gameName = slot.ArchipelagoGameName
				if gameName == "" {
					gameName = "Unknown"
				}
			}

			if len(errs) > 0 {
				valid = false
			}
			perSlotErrors[slot.SlotID] = errs

			playerName := strings.TrimSpace(slot.PlayerName)
			if playerName == "" {
				playerName = "Player"
			}
			namingInputs = append(namingInputs, preflight.SlotInput{
				SlotID:              slot.SlotID,
				PlayerName:          playerName,
				ArchipelagoGameName: gameName,
			})
		}

		proposedNames := preflight.GenerateSlotNames(namingInputs)

		results := make([]PreflightSlotResult, 0, len(req.Slots))
		for _, slot := range req.Slots {
			errs := perSlotErrors[slot.SlotID]
			if errs == nil {
				errs = []string{}
			}
			results = append(results, PreflightSlotResult{
				SlotID:       slot.SlotID,
				ProposedName: proposedNames[slot.SlotID],
				Errors:       errs,
			})
		}

		writeJSON(w, http.StatusOK, PreflightResponse{Valid: valid, Slots: results})
	}
}