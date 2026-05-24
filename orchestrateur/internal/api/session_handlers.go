package api

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"time"

	"github.com/go-chi/chi/v5"

	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/service"
)

func toSessionResponse(s *db.Session) SessionResponse {
	return SessionResponse{
		SessionID:      s.SessionID,
		Status:         s.Status,
		BridgePort:     s.BridgePort,
		APPort:         s.APPort,
		ServerPassword: s.ServerPassword,
		OutputFile:     s.OutputFile,
		CreatedAt:      s.CreatedAt.Format(time.RFC3339),
		UpdatedAt:      s.UpdatedAt.Format(time.RFC3339),
	}
}

func writeSessionError(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, service.ErrNotFound):
		writeError(w, http.StatusNotFound, "session not found")
	case errors.Is(err, service.ErrAlreadyInProgress):
		writeJSON(w, http.StatusConflict, ErrorResponse{Error: "already_in_progress"})
	case errors.Is(err, service.ErrSessionNotReady):
		writeJSON(w, http.StatusConflict, ErrorResponse{Error: "not_ready"})
	case errors.Is(err, service.ErrStorageNotConfigured):
		writeError(w, http.StatusServiceUnavailable, "storage not configured")
	default:
		writeError(w, http.StatusInternalServerError, err.Error())
	}
}

// handleGenerateSession godoc
// @Summary     Start multiworld generation
// @Description Enqueues an async multiworld generation for the given session ID.
// @Description Downloads apworlds and YAMLs from storage, runs generate_multiworld.py in a one-shot container.
// @Description Fires session.generated or session.crashed webhooks on completion.
// @Tags        sessions
// @Accept      json
// @Produce     json
// @Param       sessionId path     string               true "Session ID"
// @Param       body      body     GenerateSessionRequest true "Generation parameters"
// @Success     202
// @Failure     400 {object} ErrorResponse
// @Failure     409 {object} ErrorResponse "Already in progress"
// @Failure     503 {object} ErrorResponse "Storage not configured"
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/generate [post]
func handleGenerateSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		var req GenerateSessionRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "invalid request body")
			return
		}
		if req.AdminPassword == "" {
			writeError(w, http.StatusBadRequest, "adminPassword is required")
			return
		}

		err := svc.Generate(r.Context(), service.GenerateRequest{
			SessionID:     sessionID,
			AdminPassword: req.AdminPassword,
			Seed:          req.Seed,
		})
		if err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}
}

// handleLaunchSession godoc
// @Summary     Launch a generated session
// @Description Allocates ports, starts AP server and Bridge containers for a generated session.
// @Description Fires session.ready or session.crashed webhooks on completion.
// @Tags        sessions
// @Accept      json
// @Produce     json
// @Param       sessionId path     string              true "Session ID"
// @Param       body      body     LaunchSessionRequest true "Launch parameters"
// @Success     202
// @Failure     400 {object} ErrorResponse
// @Failure     404 {object} ErrorResponse
// @Failure     409 {object} ErrorResponse "Not ready"
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/launch [post]
func handleLaunchSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		var req LaunchSessionRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "invalid request body")
			return
		}
		if req.AdminPassword == "" {
			writeError(w, http.StatusBadRequest, "adminPassword is required")
			return
		}

		err := svc.Launch(r.Context(), service.LaunchRequest{
			SessionID:      sessionID,
			ServerPassword: req.ServerPassword,
			AdminPassword:  req.AdminPassword,
		})
		if err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}
}

// handleLaunchSessionFromFile godoc
// @Summary     Launch session from a pre-built .archipelago file
// @Description Injects a pre-generated .archipelago file into the session volume and launches.
// @Description Fires session.ready or session.crashed webhooks on completion.
// @Tags        sessions
// @Accept      multipart/form-data
// @Produce     json
// @Param       sessionId      path     string true  "Session ID"
// @Param       file           formData file   true  "The .archipelago or .zip game file"
// @Param       adminPassword  formData string true  "Admin password"
// @Param       serverPassword formData string false "Server password (optional)"
// @Success     202
// @Failure     400 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/launch-from-file [post]
func handleLaunchSessionFromFile(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")

		if err := r.ParseMultipartForm(256 << 20); err != nil {
			writeError(w, http.StatusBadRequest, "invalid multipart form")
			return
		}

		adminPassword := r.FormValue("adminPassword")
		if adminPassword == "" {
			writeError(w, http.StatusBadRequest, "adminPassword is required")
			return
		}
		serverPassword := r.FormValue("serverPassword")

		file, header, err := r.FormFile("file")
		if err != nil {
			writeError(w, http.StatusBadRequest, "file is required")
			return
		}
		defer file.Close()

		fileData, err := io.ReadAll(file)
		if err != nil {
			writeError(w, http.StatusInternalServerError, "failed to read file")
			return
		}

		if err := svc.LaunchFromFile(r.Context(), service.LaunchRequest{
			SessionID:      sessionID,
			ServerPassword: serverPassword,
			AdminPassword:  adminPassword,
		}, fileData, header.Filename); err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}
}

// handleStopSession godoc
// @Summary     Stop a running session
// @Description Sends SIGTERM to both Bridge and AP server containers and marks session as stopped.
// @Tags        sessions
// @Param       sessionId path string true "Session ID"
// @Success     204
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/stop [post]
func handleStopSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.StopSession(r.Context(), sessionID); err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}

// handleRestartSession godoc
// @Summary     Restart a crashed session
// @Description Re-launches a session that previously crashed, reusing its generated output file.
// @Tags        sessions
// @Accept      json
// @Produce     json
// @Param       sessionId path string true "Session ID"
// @Success     202
// @Failure     404 {object} ErrorResponse
// @Failure     409 {object} ErrorResponse "Not ready (no output file or wrong state)"
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/restart [post]
func handleRestartSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.RestartSession(r.Context(), sessionID); err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}
}

// handleGetSession godoc
// @Summary     Get session
// @Description Returns the current state of a session
// @Tags        sessions
// @Produce     json
// @Param       sessionId path     string true "Session ID"
// @Success     200       {object} SessionResponse
// @Failure     404       {object} ErrorResponse
// @Failure     500       {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId} [get]
func handleGetSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		sess, err := svc.GetSession(r.Context(), sessionID)
		if err != nil {
			writeSessionError(w, err)
			return
		}
		writeJSON(w, http.StatusOK, toSessionResponse(sess))
	}
}

// handleDeleteSession godoc
// @Summary     Delete session
// @Description Force-removes all resources for a session (containers, volume, DB record)
// @Tags        sessions
// @Param       sessionId path string true "Session ID"
// @Success     204
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /sessions/{sessionId} [delete]
func handleDeleteSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.DeleteSession(r.Context(), sessionID); err != nil {
			writeSessionError(w, err)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}

// handleConfigureSession godoc
// @Summary     Configure (draft) a session
// @Description Validates slot definitions, uploads player YAMLs and manifest to storage,
// @Description and upserts the session as "draft". Idempotent: safe to call multiple times.
// @Description Does NOT persist anything if any slot fails validation.
// @Tags        sessions
// @Accept      json
// @Produce     json
// @Param       sessionId path     string                 true "Session ID"
// @Param       body      body     ConfigureSessionRequest true "Slot definitions"
// @Success     200       {object} ConfigureResponse
// @Failure     400       {object} ErrorResponse
// @Failure     409       {object} ErrorResponse "Session is generating, launching, or running"
// @Failure     503       {object} ErrorResponse "Storage not configured"
// @Security    BearerAuth
// @Router      /sessions/{sessionId}/configure [post]
func handleConfigureSession(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")

		var req ConfigureSessionRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeError(w, http.StatusBadRequest, "invalid request body")
			return
		}
		if len(req.Slots) == 0 {
			writeError(w, http.StatusBadRequest, "slots is required and must not be empty")
			return
		}

		inputs := make([]service.ConfigureSlotInput, 0, len(req.Slots))
		for _, s := range req.Slots {
			input := service.ConfigureSlotInput{
				ApworldHash: s.ApworldHash,
				PlayerYaml:  s.PlayerYaml,
			}
			if s.Options != nil {
				input.Options = &service.SlotOptionsPayload{
					PlayerName: s.Options.PlayerName,
					Values:     s.Options.Values,
				}
			}
			inputs = append(inputs, input)
		}

		result, err := svc.ConfigureSession(r.Context(), service.ConfigureRequest{
			SessionID: sessionID,
			Slots:     inputs,
		})
		if err != nil {
			writeSessionError(w, err)
			return
		}

		slots := make([]ConfigureSlotResponse, 0, len(result.Slots))
		for _, s := range result.Slots {
			slots = append(slots, ConfigureSlotResponse{
				PlayerName: s.PlayerName,
				Errors:     s.Errors,
			})
		}
		writeJSON(w, http.StatusOK, ConfigureResponse{Valid: result.Valid, Slots: slots})
	}
}
