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
	"archilan.fr/orchestrateur/internal/templateparser"
)

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, ErrorResponse{Error: msg})
}

func toResponse(c *db.Container) ContainerResponse {
	return ContainerResponse{
		SessionID:   c.SessionID,
		Port:        c.Port,
		Status:      c.Status,
		ContainerID: c.ContainerID,
		Image:       c.Image,
		CreatedAt:   c.CreatedAt.Format(time.RFC3339),
		UpdatedAt:   c.UpdatedAt.Format(time.RFC3339),
	}
}

// handleHealth godoc
// @Summary     Health check
// @Description Returns ok when the service is up
// @Tags        system
// @Produce     json
// @Success     200 {object} HealthResponse
// @Router      /health [get]
func handleHealth() http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, HealthResponse{Status: "ok"})
	}
}

// handleUploadApworld godoc
// @Summary     Upload an apworld and generate its YAML template
// @Description Stores the apworld in Minio, runs a one-shot Archipelago container to generate
// @Description the default YAML template, stores it, and returns both the hash and the YAML.
// @Tags        apworlds
// @Accept      multipart/form-data
// @Produce     json
// @Param       file formData file true "The .apworld file"
// @Success     201 {object} UploadApworldResponse
// @Failure     400 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Failure     503 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /apworlds [post]
func handleUploadApworld(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if err := r.ParseMultipartForm(64 << 20); err != nil {
			writeError(w, http.StatusBadRequest, "invalid multipart form")
			return
		}

		file, _, err := r.FormFile("file")
		if err != nil {
			writeError(w, http.StatusBadRequest, "file is required")
			return
		}
		defer file.Close()

		data, err := io.ReadAll(file)
		if err != nil {
			writeError(w, http.StatusInternalServerError, "failed to read file")
			return
		}

		hash, yamlData, err := svc.UploadApworld(r.Context(), data)
		if errors.Is(err, service.ErrStorageNotConfigured) {
			writeError(w, http.StatusServiceUnavailable, "storage not configured")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		parsed := templateparser.Parse(yamlData)
		apiOptions := make([]TemplateOption, len(parsed))
		for i, o := range parsed {
			apiOptions[i] = TemplateOption{
				Key:          o.Key,
				Description:  o.Description,
				Type:         o.Type,
				DefaultValue: o.DefaultValue,
				ValidValues:  o.ValidValues,
				Weights:      o.Weights,
				RangeMin:     o.RangeMin,
				RangeMax:     o.RangeMax,
			}
		}
		writeJSON(w, http.StatusCreated, UploadApworldResponse{
			Hash:    hash,
			Options: apiOptions,
		})
	}
}

// handleListApworlds godoc
// @Summary     List uploaded apworlds
// @Description Returns all apworlds stored in Minio with their game name and version
// @Tags        apworlds
// @Produce     json
// @Success     200 {object} ApworldListResponse
// @Failure     503 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /apworlds [get]
func handleListApworlds(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		entries, err := svc.ListApworlds(r.Context())
		if errors.Is(err, service.ErrStorageNotConfigured) {
			writeError(w, http.StatusServiceUnavailable, "storage not configured")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		resp := ApworldListResponse{Apworlds: make([]ApworldEntry, 0, len(entries))}
		for _, e := range entries {
			resp.Apworlds = append(resp.Apworlds, ApworldEntry{
				Hash: e.Hash,
				Game: e.Game,
			})
		}
		writeJSON(w, http.StatusOK, resp)
	}
}

// handleGetApworldTemplate godoc
// @Summary     Get apworld default YAML template
// @Description Returns the default YAML template for the given apworld hash
// @Tags        apworlds
// @Param       hash path string true "Apworld SHA-256 hash"
// @Produce     plain
// @Success     200 {string} string
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /apworlds/{hash}/yaml [get]
func handleGetApworldTemplate(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		hash := chi.URLParam(r, "hash")
		data, err := svc.GetApworldTemplate(r.Context(), hash)
		if errors.Is(err, service.ErrNotFound) {
			writeError(w, http.StatusNotFound, "template not found")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		w.Header().Set("Content-Type", "text/yaml; charset=utf-8")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(data)
	}
}

// handleGetApworldOptions godoc
// @Summary     Get apworld parsed options
// @Description Returns the structured game options extracted from the stored YAML template
// @Tags        apworlds
// @Param       hash path string true "Apworld SHA-256 hash"
// @Produce     json
// @Success     200 {object} ApworldOptionsResponse
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /apworlds/{hash}/options [get]
func handleGetApworldOptions(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		hash := chi.URLParam(r, "hash")
		data, err := svc.GetApworldTemplate(r.Context(), hash)
		if errors.Is(err, service.ErrNotFound) {
			writeError(w, http.StatusNotFound, "template not found")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		parsed := templateparser.Parse(data)
		overrides := svc.GetApworldOptionTypes(r.Context(), hash)

		opts := make([]TemplateOption, len(parsed))
		for i, o := range parsed {
			opt := TemplateOption{
				Key:          o.Key,
				Description:  o.Description,
				Type:         o.Type,
				DefaultValue: o.DefaultValue,
				ValidValues:  o.ValidValues,
				Weights:      o.Weights,
				RangeMin:     o.RangeMin,
				RangeMax:     o.RangeMax,
			}
			if ov, ok := overrides[o.Key]; ok {
				opt.Type = ov.Type
				opt.Weights = nil // weights don't apply to the introspected weights type
				if ov.Type == "weights" && ov.DefaultWeights != nil {
					opt.DefaultValue = ov.DefaultWeights
				}
			}
			opts[i] = opt
		}
		writeJSON(w, http.StatusOK, ApworldOptionsResponse{Options: opts})
	}
}

// handleListContainers godoc
// @Summary     List containers
// @Description Returns all managed Bridge containers
// @Tags        containers
// @Produce     json
// @Success     200 {object} ContainersResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /containers [get]
func handleListContainers(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		containers, err := svc.List()
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		resp := ContainersResponse{Containers: make([]ContainerResponse, 0, len(containers))}
		for _, c := range containers {
			resp.Containers = append(resp.Containers, toResponse(c))
		}
		writeJSON(w, http.StatusOK, resp)
	}
}

// handleGetContainer godoc
// @Summary     Get container
// @Description Returns a single managed Bridge container by session ID
// @Tags        containers
// @Produce     json
// @Param       sessionId path     string true "Session ID"
// @Success     200       {object} ContainerResponse
// @Failure     404       {object} ErrorResponse
// @Failure     500       {object} ErrorResponse
// @Security    BearerAuth
// @Router      /containers/{sessionId} [get]
func handleGetContainer(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		c, err := svc.Get(r.Context(), sessionID)
		if errors.Is(err, service.ErrNotFound) {
			writeError(w, http.StatusNotFound, "session not found")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		writeJSON(w, http.StatusOK, toResponse(c))
	}
}

// handleCreateContainer godoc
// @Summary     Create container
// @Description Allocates a port, starts a Bridge container, and records it. Returns immediately; webhook fires when container is ready.
// @Tags        containers
// @Accept      json
// @Produce     json
// @Param       body body     CreateContainerRequest true "Session info"
// @Success     202  {object} CreateContainerResponse
// @Failure     400  {object} ErrorResponse
// @Failure     409  {object} ErrorResponse "Session already exists"
// @Failure     503  {object} ErrorResponse "Port pool exhausted"
// @Security    BearerAuth
// @Router      /containers [post]
func handleCreateContainer(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		var req CreateContainerRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.SessionID == "" {
			writeError(w, http.StatusBadRequest, "sessionId is required")
			return
		}
		if req.AdminPassword == "" {
			writeError(w, http.StatusBadRequest, "adminPassword is required")
			return
		}

		port, err := svc.Create(r.Context(), service.CreateRequest{
			SessionID:      req.SessionID,
			ServerPassword: req.ServerPassword,
			AdminPassword:  req.AdminPassword,
		})
		if errors.Is(err, service.ErrAlreadyExists) {
			writeError(w, http.StatusConflict, "session already exists")
			return
		}
		if errors.Is(err, service.ErrPortExhausted) {
			writeError(w, http.StatusServiceUnavailable, "port pool exhausted")
			return
		}
		if err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		writeJSON(w, http.StatusAccepted, CreateContainerResponse{
			SessionID: req.SessionID,
			Port:      port,
			Status:    "starting",
		})
	}
}

// handleStopContainer godoc
// @Summary     Stop container
// @Description Sends SIGTERM to a running Bridge container
// @Tags        containers
// @Param       sessionId path string true "Session ID"
// @Success     204
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /containers/{sessionId}/stop [post]
func handleStopContainer(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.Stop(r.Context(), sessionID); err != nil {
			if errors.Is(err, service.ErrNotFound) {
				writeError(w, http.StatusNotFound, "session not found")
				return
			}
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}

// handleReloadContainer godoc
// @Summary     Reload container
// @Description Restarts a Bridge container
// @Tags        containers
// @Param       sessionId path string true "Session ID"
// @Success     204
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /containers/{sessionId}/reload [post]
func handleReloadContainer(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.Reload(r.Context(), sessionID); err != nil {
			if errors.Is(err, service.ErrNotFound) {
				writeError(w, http.StatusNotFound, "session not found")
				return
			}
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}

// handleRemoveContainer godoc
// @Summary     Remove container
// @Description Force-removes a Bridge container and releases its port
// @Tags        containers
// @Param       sessionId path string true "Session ID"
// @Success     204
// @Failure     404 {object} ErrorResponse
// @Failure     500 {object} ErrorResponse
// @Security    BearerAuth
// @Router      /containers/{sessionId} [delete]
func handleRemoveContainer(svc *service.Service) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		sessionID := chi.URLParam(r, "sessionId")
		if err := svc.Remove(r.Context(), sessionID); err != nil {
			if errors.Is(err, service.ErrNotFound) {
				writeError(w, http.StatusNotFound, "session not found")
				return
			}
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}
}
