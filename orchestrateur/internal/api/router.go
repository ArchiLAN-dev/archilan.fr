package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"

	"archilan.fr/orchestrateur/internal/config"
	"archilan.fr/orchestrateur/internal/service"
)

func NewRouter(cfg *config.Config, svc *service.Service) http.Handler {
	r := chi.NewRouter()

	r.Use(middleware.RequestID)
	r.Use(middleware.RealIP)
	r.Use(middleware.Logger)
	r.Use(middleware.Recoverer)

	r.Get("/health", handleHealth())

	r.Group(func(r chi.Router) {
		r.Use(authMiddleware(cfg.APIKey))

		r.Get("/containers", handleListContainers(svc))
		r.Post("/containers", handleCreateContainer(svc))

		r.Get("/containers/{sessionId}", handleGetContainer(svc))
		r.Delete("/containers/{sessionId}", handleRemoveContainer(svc))
		r.Post("/containers/{sessionId}/stop", handleStopContainer(svc))
		r.Post("/containers/{sessionId}/reload", handleReloadContainer(svc))
	})

	return r
}
