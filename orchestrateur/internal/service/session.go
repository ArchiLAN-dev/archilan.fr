package service

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"time"

	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/docker"
	"archilan.fr/orchestrateur/internal/webhook"
)

var (
	ErrAlreadyInProgress = errors.New("session already in progress")
	ErrSessionNotReady   = errors.New("session not ready")
)

// GenerateRequest carries the parameters for a multiworld generation.
type GenerateRequest struct {
	SessionID     string
	AdminPassword string
	Seed          string // optional
}

// LaunchRequest carries the parameters for launching a session.
type LaunchRequest struct {
	SessionID      string
	ServerPassword string
	AdminPassword  string
}

// Generate starts an async multiworld generation for the given session.
// Returns ErrStorageNotConfigured if Minio is not configured.
// Returns ErrAlreadyInProgress if the session is already generating or launching.
func (s *Service) Generate(ctx context.Context, req GenerateRequest) error {
	if s.storage == nil {
		return ErrStorageNotConfigured
	}

	existing, err := s.db.GetSession(req.SessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if existing != nil {
		switch existing.Status {
		case "generating", "launching", "running":
			return ErrAlreadyInProgress
		}
	}

	tarBuf, _, err := s.buildDataTar(ctx, req.SessionID)
	if err != nil {
		return fmt.Errorf("build data tar: %w", err)
	}

	now := time.Now().UTC()
	if existing == nil {
		if err := s.db.InsertSession(&db.Session{
			SessionID: req.SessionID,
			Status:    "pending",
			CreatedAt: now,
			UpdatedAt: now,
		}); err != nil {
			return fmt.Errorf("insert session: %w", err)
		}
	} else {
		if err := s.db.UpdateSessionStatus(req.SessionID, "pending", nil); err != nil {
			return fmt.Errorf("reset session status: %w", err)
		}
	}

	go s.runGeneration(req.SessionID, req.AdminPassword, tarBuf, req.Seed)
	return nil
}

func (s *Service) runGeneration(sessionID, adminPassword string, tarData *bytes.Buffer, seed string) {
	ctx := context.Background()
	deadline := time.Now().UTC().Add(s.cfg.GenerationTimeout)

	if err := s.db.UpdateSessionGenerating(sessionID, "", deadline); err != nil {
		s.log.Error("runGeneration: UpdateSessionGenerating failed", "session_id", sessionID, "err", err)
	}

	outputFile, jobID, err := s.docker.GenerateMultiworld(ctx, sessionID, seed, tarData)
	// Store jobID now that we have it (container may already be removed, but useful for audit)
	if jobID != "" {
		_ = s.db.UpdateSessionGenerating(sessionID, jobID, deadline)
	}

	if err != nil {
		s.log.Error("runGeneration: GenerateMultiworld failed", "session_id", sessionID, "err", err)
		_ = s.db.UpdateSessionCrashed(sessionID)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "session.crashed",
			SessionID: sessionID,
			Error:     err.Error(),
		})
		return
	}

	if err := s.db.UpdateSessionGenerated(sessionID, outputFile); err != nil {
		s.log.Error("runGeneration: UpdateSessionGenerated failed", "session_id", sessionID, "err", err)
	}
	s.webhook.Send(ctx, webhook.Payload{
		Event:     "session.generated",
		SessionID: sessionID,
	})
	s.log.Info("generation complete", "session_id", sessionID, "output_file", outputFile)
}

// Launch starts an async session launch. The session must be in the "generated" state.
func (s *Service) Launch(ctx context.Context, req LaunchRequest) error {
	sess, err := s.db.GetSession(req.SessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if sess == nil {
		return ErrNotFound
	}
	if sess.Status != "generated" {
		return ErrSessionNotReady
	}

	bridgePort, err := s.pool.Acquire(req.SessionID)
	if err != nil {
		return err
	}
	apPort := bridgePort + s.cfg.APServerPortOffset

	deadline := time.Now().UTC().Add(s.cfg.LaunchTimeout)
	if err := s.db.UpdateSessionLaunching(req.SessionID, bridgePort, apPort, req.ServerPassword, req.AdminPassword, deadline); err != nil {
		s.pool.Release(bridgePort)
		return fmt.Errorf("update session launching: %w", err)
	}

	go s.startSession(req.SessionID, bridgePort, apPort, req.ServerPassword, req.AdminPassword)
	return nil
}

func (s *Service) startSession(sessionID string, bridgePort, apPort int, serverPassword, adminPassword string) {
	ctx := context.Background()

	crash := func(errMsg string) {
		s.log.Error("startSession crashed", "session_id", sessionID, "err", errMsg)
		s.pool.Release(bridgePort)
		_ = s.db.UpdateSessionCrashed(sessionID)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "session.crashed",
			SessionID: sessionID,
			Error:     errMsg,
		})
	}

	// 1. Create AP server container
	apContainerID, err := s.docker.CreateAPServer(ctx, docker.APServerCreateConfig{
		SessionID:      sessionID,
		APPort:         apPort,
		ServerPassword: serverPassword,
		APImage:        s.cfg.APImage,
		BridgeNetwork:  s.cfg.BridgeNetwork,
	})
	if err != nil {
		crash(fmt.Sprintf("create ap server: %s", err))
		return
	}

	// 2. Start AP server container
	if err := s.docker.Start(ctx, apContainerID, sessionID, apPort); err != nil {
		_ = s.docker.Remove(ctx, apContainerID)
		crash(fmt.Sprintf("start ap server: %s", err))
		return
	}

	// 3. Wait for AP server port to be open
	if !s.docker.WaitForPort(ctx, apPort, 60*time.Second) {
		_ = s.docker.Stop(ctx, apContainerID)
		_ = s.docker.Remove(ctx, apContainerID)
		crash("ap server port not reachable within 60s")
		return
	}

	// 4. Create Bridge container (volume already has data from generation step)
	bridgeContainerID, err := s.docker.Create(ctx, docker.CreateConfig{
		SessionID:      sessionID,
		Port:           bridgePort,
		BridgeToken:    s.cfg.BridgeToken,
		APImage:        s.cfg.APImage,
		ServerPassword: serverPassword,
		AdminPassword:  adminPassword,
	})
	if err != nil {
		_ = s.docker.Stop(ctx, apContainerID)
		_ = s.docker.Remove(ctx, apContainerID)
		crash(fmt.Sprintf("create bridge container: %s", err))
		return
	}

	// 5. Start Bridge container
	if err := s.docker.Start(ctx, bridgeContainerID, sessionID, bridgePort); err != nil {
		_ = s.docker.Remove(ctx, bridgeContainerID)
		_ = s.docker.Stop(ctx, apContainerID)
		_ = s.docker.Remove(ctx, apContainerID)
		crash(fmt.Sprintf("start bridge container: %s", err))
		return
	}

	// 6. Update session to running
	if err := s.db.UpdateSessionRunning(sessionID, bridgeContainerID, apContainerID); err != nil {
		s.log.Error("startSession: UpdateSessionRunning failed", "session_id", sessionID, "err", err)
	}

	// 7. Fire session.ready webhook
	s.webhook.Send(ctx, webhook.Payload{
		Event:     "session.ready",
		SessionID: sessionID,
		Port:      bridgePort,
	})
	s.log.Info("session running", "session_id", sessionID, "bridge_port", bridgePort, "ap_port", apPort)
}

// LaunchFromFile injects a pre-built .archipelago file into the session volume and launches.
func (s *Service) LaunchFromFile(ctx context.Context, req LaunchRequest, fileData []byte, filename string) error {
	// Ensure session record exists
	existing, err := s.db.GetSession(req.SessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if existing != nil {
		switch existing.Status {
		case "generating", "launching", "running":
			return ErrAlreadyInProgress
		}
	}
	now := time.Now().UTC()
	if existing == nil {
		if err := s.db.InsertSession(&db.Session{
			SessionID: req.SessionID,
			Status:    "pending",
			CreatedAt: now,
			UpdatedAt: now,
		}); err != nil {
			return fmt.Errorf("insert session: %w", err)
		}
	}

	if err := s.docker.InjectFileToVolume(ctx, req.SessionID, filename, fileData); err != nil {
		return fmt.Errorf("inject file to volume: %w", err)
	}

	if err := s.db.UpdateSessionGenerated(req.SessionID, filename); err != nil {
		return fmt.Errorf("update session generated: %w", err)
	}

	return s.Launch(ctx, req)
}

// StopSession stops both containers and releases the port, marking the session as stopped.
func (s *Service) StopSession(ctx context.Context, sessionID string) error {
	sess, err := s.db.GetSession(sessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if sess == nil {
		return ErrNotFound
	}

	if sess.BridgeContainerID != nil {
		_ = s.docker.Stop(ctx, *sess.BridgeContainerID)
	}
	if sess.APContainerID != nil {
		_ = s.docker.Stop(ctx, *sess.APContainerID)
	}
	if sess.BridgePort != nil {
		s.pool.Release(*sess.BridgePort)
	}

	return s.db.UpdateSessionStopped(sessionID)
}

// DeleteSession removes all resources for a session (containers, volume, DB record).
func (s *Service) DeleteSession(ctx context.Context, sessionID string) error {
	sess, err := s.db.GetSession(sessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if sess == nil {
		return ErrNotFound
	}

	if sess.BridgeContainerID != nil {
		_ = s.docker.Remove(ctx, *sess.BridgeContainerID)
	}
	_ = s.docker.RemoveAPServer(ctx, sessionID)
	_ = s.docker.RemoveVolume(ctx, sessionID)

	if sess.BridgePort != nil && sess.Status != "stopped" && sess.Status != "crashed" {
		s.pool.Release(*sess.BridgePort)
	}

	return s.db.DeleteSession(sessionID)
}

// RestartSession attempts to restart a crashed session from its generated output file.
func (s *Service) RestartSession(ctx context.Context, sessionID string) error {
	sess, err := s.db.GetSession(sessionID)
	if err != nil {
		return fmt.Errorf("get session: %w", err)
	}
	if sess == nil {
		return ErrNotFound
	}
	if sess.Status != "crashed" {
		return ErrSessionNotReady
	}
	if sess.OutputFile == nil {
		return ErrSessionNotReady
	}

	// Best-effort cleanup
	_ = s.StopSession(ctx, sessionID)

	serverPassword := ""
	if sess.ServerPassword != nil {
		serverPassword = *sess.ServerPassword
	}
	adminPassword := ""
	if sess.AdminPassword != nil {
		adminPassword = *sess.AdminPassword
	}

	if err := s.db.UpdateSessionStatus(sessionID, "generated", nil); err != nil {
		return fmt.Errorf("reset session to generated: %w", err)
	}

	return s.Launch(ctx, LaunchRequest{
		SessionID:      sessionID,
		ServerPassword: serverPassword,
		AdminPassword:  adminPassword,
	})
}

// GetSession returns the session with the given ID.
func (s *Service) GetSession(ctx context.Context, sessionID string) (*db.Session, error) {
	sess, err := s.db.GetSession(sessionID)
	if err != nil {
		return nil, fmt.Errorf("get session: %w", err)
	}
	if sess == nil {
		return nil, ErrNotFound
	}
	return sess, nil
}
