package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
	"github.com/go-chi/cors"
	httpSwagger "github.com/swaggo/http-swagger/v2"

	"archilan.fr/orchestrateur/internal/config"
	"archilan.fr/orchestrateur/internal/service"
)

func NewRouter(cfg *config.Config, svc *service.Service) http.Handler {
	r := chi.NewRouter()

	r.Use(middleware.RequestID)
	r.Use(middleware.RealIP)
	r.Use(middleware.Logger)
	r.Use(middleware.Recoverer)
	r.Use(cors.Handler(cors.Options{
		AllowedOrigins: cfg.CORSOrigins,
		AllowedMethods: []string{"GET", "POST", "DELETE", "OPTIONS"},
		AllowedHeaders: []string{"Authorization", "Content-Type"},
		MaxAge:         300,
	}))

	r.Get("/health", handleHealth())
	r.Get("/docs/*", httpSwagger.Handler(
		httpSwagger.URL("doc.json"),
	))

	r.Group(func(r chi.Router) {
		r.Use(authMiddleware(cfg.APIKey))

		r.Get("/apworlds", handleListApworlds(svc))
		r.Post("/apworlds", handleUploadApworld(svc))
		r.Get("/apworlds/{hash}/yaml", handleGetApworldTemplate(svc))
		r.Get("/apworlds/{hash}/options", handleGetApworldOptions(svc))

		r.Get("/containers", handleListContainers(svc))
		r.Post("/containers", handleCreateContainer(svc))

		r.Get("/containers/{sessionId}", handleGetContainer(svc))
		r.Delete("/containers/{sessionId}", handleRemoveContainer(svc))
		r.Post("/containers/{sessionId}/stop", handleStopContainer(svc))
		r.Post("/containers/{sessionId}/reload", handleReloadContainer(svc))

		r.Post("/sessions/{sessionId}/configure", handleConfigureSession(svc))
		r.Post("/sessions/{sessionId}/preflight", handlePreflight())
		r.Post("/sessions/{sessionId}/generate", handleGenerateSession(svc))
		r.Post("/sessions/{sessionId}/launch", handleLaunchSession(svc))
		r.Post("/sessions/{sessionId}/launch-from-file", handleLaunchSessionFromFile(svc))
		r.Post("/sessions/{sessionId}/stop", handleStopSession(svc))
		r.Post("/sessions/{sessionId}/restart", handleRestartSession(svc))
		r.Get("/sessions/{sessionId}", handleGetSession(svc))
		r.Delete("/sessions/{sessionId}", handleDeleteSession(svc))
	})

	return r
}
