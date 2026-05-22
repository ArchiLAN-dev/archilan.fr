package service

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"archilan.fr/orchestrateur/internal/config"
	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/docker"
	"archilan.fr/orchestrateur/internal/portpool"
	"archilan.fr/orchestrateur/internal/webhook"
)

var (
	ErrNotFound      = errors.New("session not found")
	ErrAlreadyExists = errors.New("session already exists")
	ErrPortExhausted = portpool.ErrExhausted
)

type Service struct {
	db      *db.DB
	docker  *docker.Client
	pool    *portpool.Pool
	webhook *webhook.Sender
	cfg     *config.Config
	log     *slog.Logger
}

func New(
	database *db.DB,
	dockerClient *docker.Client,
	pool *portpool.Pool,
	webhookSender *webhook.Sender,
	cfg *config.Config,
	log *slog.Logger,
) *Service {
	return &Service{
		db:      database,
		docker:  dockerClient,
		pool:    pool,
		webhook: webhookSender,
		cfg:     cfg,
		log:     log,
	}
}

// RecoverFromDB restores the port pool from persisted container records.
func (s *Service) RecoverFromDB(ctx context.Context) error {
	ports, err := s.db.AllPorts()
	if err != nil {
		return fmt.Errorf("recover: %w", err)
	}
	for port, sessionID := range ports {
		s.pool.Reserve(port, sessionID)
		s.log.Info("recovered session from db", "session_id", sessionID, "port", port)
	}
	return nil
}

type CreateRequest struct {
	SessionID string
}

// Create allocates a port, starts a Bridge container, and records it in the DB.
// It returns immediately; the container.ready webhook fires asynchronously once healthy.
func (s *Service) Create(ctx context.Context, req CreateRequest) (int, error) {
	existing, err := s.db.Get(req.SessionID)
	if err != nil {
		return 0, err
	}
	if existing != nil {
		return 0, ErrAlreadyExists
	}

	port, err := s.pool.Acquire(req.SessionID)
	if err != nil {
		return 0, err
	}

	now := time.Now().UTC()
	if err := s.db.Insert(&db.Container{
		SessionID: req.SessionID,
		Port:      port,
		Status:    "starting",
		Image:     s.cfg.BridgeImage,
		CreatedAt: now,
		UpdatedAt: now,
	}); err != nil {
		s.pool.Release(port)
		return 0, fmt.Errorf("db insert: %w", err)
	}

	go s.startContainer(req.SessionID, port)

	return port, nil
}

func (s *Service) startContainer(sessionID string, port int) {
	ctx := context.Background()

	containerID, err := s.docker.CreateAndStart(ctx, docker.CreateConfig{
		SessionID:   sessionID,
		Port:        port,
		BridgeToken: s.cfg.BridgeToken,
	})
	if err != nil {
		s.log.Error("container start failed", "session_id", sessionID, "err", err)
		_ = s.db.UpdateStatus(sessionID, "crashed", nil)
		s.pool.Release(port)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "container.crashed",
			SessionID: sessionID,
			Port:      port,
			Error:     err.Error(),
		})
		return
	}

	_ = s.db.UpdateStatus(sessionID, "starting", &containerID)
	s.log.Info("container created", "session_id", sessionID, "container_id", containerID, "port", port)
}

// HandleDockerEvent processes events from the Docker event stream.
func (s *Service) HandleDockerEvent(ctx context.Context, event docker.Event) {
	c, err := s.db.Get(event.SessionID)
	if err != nil || c == nil {
		return
	}

	switch event.Type {
	case docker.EventStart:
		_ = s.db.UpdateStatus(event.SessionID, "running", &event.ContainerID)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "container.ready",
			SessionID: event.SessionID,
			Port:      c.Port,
		})
		s.log.Info("container ready", "session_id", event.SessionID, "port", c.Port)

	case docker.EventDie:
		_ = s.db.UpdateStatus(event.SessionID, "crashed", &event.ContainerID)
		s.pool.Release(c.Port)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "container.crashed",
			SessionID: event.SessionID,
			Port:      c.Port,
			Error:     fmt.Sprintf("exit code %s", event.ExitCode),
		})
		s.log.Warn("container crashed", "session_id", event.SessionID, "exit_code", event.ExitCode)
	}
}

func (s *Service) Stop(ctx context.Context, sessionID string) error {
	c, err := s.db.Get(sessionID)
	if err != nil {
		return err
	}
	if c == nil {
		return ErrNotFound
	}
	if c.ContainerID == nil {
		return fmt.Errorf("container not yet created")
	}
	if err := s.docker.Stop(ctx, *c.ContainerID); err != nil {
		return err
	}
	return s.db.UpdateStatus(sessionID, "stopped", c.ContainerID)
}

func (s *Service) Reload(ctx context.Context, sessionID string) error {
	c, err := s.db.Get(sessionID)
	if err != nil {
		return err
	}
	if c == nil {
		return ErrNotFound
	}
	if c.ContainerID == nil {
		return fmt.Errorf("container not yet created")
	}
	if err := s.docker.Restart(ctx, *c.ContainerID); err != nil {
		return err
	}
	return s.db.UpdateStatus(sessionID, "starting", c.ContainerID)
}

func (s *Service) Remove(ctx context.Context, sessionID string) error {
	c, err := s.db.Get(sessionID)
	if err != nil {
		return err
	}
	if c == nil {
		return ErrNotFound
	}
	if c.ContainerID != nil {
		if err := s.docker.Remove(ctx, *c.ContainerID); err != nil {
			return err
		}
	}
	s.pool.Release(c.Port)
	return s.db.Delete(sessionID)
}

func (s *Service) Get(sessionID string) (*db.Container, error) {
	c, err := s.db.Get(sessionID)
	if err != nil {
		return nil, err
	}
	if c == nil {
		return nil, ErrNotFound
	}
	return c, nil
}

func (s *Service) List() ([]*db.Container, error) {
	return s.db.List()
}
