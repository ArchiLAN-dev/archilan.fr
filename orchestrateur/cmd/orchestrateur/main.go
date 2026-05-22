// @title           Archilan Orchestrateur API
// @version         1.0
// @description     Gestion des containers Bridge Archipelago — création, arrêt, suppression et monitoring.
// @BasePath        /
// @securityDefinitions.apikey BearerAuth
// @in              header
// @name            Authorization
// @description     Bearer <API_KEY>
package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "archilan.fr/orchestrateur/docs"
	"archilan.fr/orchestrateur/internal/api"
	"archilan.fr/orchestrateur/internal/config"
	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/docker"
	"archilan.fr/orchestrateur/internal/portpool"
	"archilan.fr/orchestrateur/internal/service"
	"archilan.fr/orchestrateur/internal/storage"
	"archilan.fr/orchestrateur/internal/webhook"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))

	cfg := config.Load()

	database, err := db.New(cfg.DBPath)
	if err != nil {
		log.Error("db init failed", "err", err)
		os.Exit(1)
	}

	dockerClient, err := docker.New(cfg, log)
	if err != nil {
		log.Error("docker client init failed", "err", err)
		os.Exit(1)
	}

	var storageCl *storage.Client
	if cfg.MinioEndpoint != "" {
		sc, err := storage.New(storage.Config{
			Endpoint:       cfg.MinioEndpoint,
			AccessKey:      cfg.MinioAccessKey,
			SecretKey:      cfg.MinioSecretKey,
			UseSSL:         cfg.MinioUseSSL,
			BucketApworlds: cfg.MinioBucketApworlds,
			BucketSessions: cfg.MinioBucketSessions,
		})
		if err != nil {
			log.Error("storage init failed", "err", err)
			os.Exit(1)
		}
		storageCl = sc
		log.Info("minio storage connected", "endpoint", cfg.MinioEndpoint)
	}

	pool := portpool.New(cfg.PortRangeStart, cfg.PortRangeEnd)
	webhookSender := webhook.New(cfg.WebhookURL, cfg.WebhookSecret, log)
	svc := service.New(database, dockerClient, pool, webhookSender, storageCl, cfg, log)

	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()

	if err := svc.RecoverFromDB(ctx); err != nil {
		log.Error("recovery failed", "err", err)
		os.Exit(1)
	}

	go svc.RunSweeper(ctx)

	// Watch Docker events in background.
	go func() {
		for {
			events, errc := dockerClient.WatchEvents(ctx)
			for event := range events {
				svc.HandleDockerEvent(ctx, event)
			}
			select {
			case <-ctx.Done():
				return
			case err := <-errc:
				if err != nil {
					log.Error("docker events error, reconnecting", "err", err)
					time.Sleep(5 * time.Second)
				}
			}
		}
	}()

	srv := &http.Server{
		Addr:    fmt.Sprintf(":%d", cfg.Port),
		Handler: api.NewRouter(cfg, svc),
	}

	go func() {
		log.Info("orchestrateur started", "port", cfg.Port)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Error("server error", "err", err)
			os.Exit(1)
		}
	}()

	<-ctx.Done()
	log.Info("shutting down")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer shutdownCancel()
	_ = srv.Shutdown(shutdownCtx)
}
