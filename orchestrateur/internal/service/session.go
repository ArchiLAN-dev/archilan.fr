package service

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	yaml "gopkg.in/yaml.v2"

	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/docker"
	"archilan.fr/orchestrateur/internal/storage"
	"archilan.fr/orchestrateur/internal/templateparser"
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
		AdminPassword:  adminPassword,
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

	// 3. Wait for AP server to be reachable via its Docker network hostname.
	// The orchestrateur itself runs in a container, so probing localhost:{apPort}
	// (the host-mapped port) would fail — probe the internal address instead.
	apAddr := fmt.Sprintf("ap-server-%s:38281", sessionID)
	if !s.docker.WaitForAddress(ctx, apAddr, 60*time.Second) {
		_ = s.docker.Stop(ctx, apContainerID)
		_ = s.docker.Remove(ctx, apContainerID)
		crash("ap server port not reachable within 60s")
		return
	}

	// 4. Create Bridge container (volume already has data from generation step)
	bridgeContainerID, err := s.docker.Create(ctx, docker.CreateConfig{
		SessionID:        sessionID,
		Port:             bridgePort,
		BridgeToken:      s.cfg.BridgeToken,
		APImage:          s.cfg.APImage,
		ServerPassword:   serverPassword,
		AdminPassword:    adminPassword,
		CentralAPIURL:    s.cfg.CentralAPIURL,
		CentralAPISecret: s.cfg.CentralAPISecret,
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
		Event:      "session.ready",
		SessionID:  sessionID,
		Port:       apPort,
		BridgePort: bridgePort,
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

	if err := s.db.UpdateSessionStopped(sessionID); err != nil {
		return err
	}
	s.webhook.Send(ctx, webhook.Payload{
		Event:     "session.stopped",
		SessionID: sessionID,
	})
	return nil
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

// ---------------------------------------------------------------------------
// Configure
// ---------------------------------------------------------------------------

// SlotOptionsPayload carries structured options for server-side YAML generation.
type SlotOptionsPayload struct {
	PlayerName string
	Values     map[string]any
}

// ConfigureSlotInput is one slot entry for ConfigureSession.
// Either PlayerYaml or Options must be set; Options takes priority when both are present.
type ConfigureSlotInput struct {
	ApworldHash string
	PlayerYaml  string // mutually exclusive with Options
	Options     *SlotOptionsPayload
}

// apworldOptionKeys returns the set of valid option keys for a given template YAML.
func apworldOptionKeys(tmplData []byte) map[string]bool {
	opts := templateparser.Parse(tmplData)
	keys := make(map[string]bool, len(opts))
	for _, o := range opts {
		keys[o.Key] = true
	}
	return keys
}

// buildPlayerYaml generates an Archipelago player YAML from structured option values.
func buildPlayerYaml(playerName, gameName string, values map[string]any) (string, error) {
	if values == nil {
		values = map[string]any{}
	}
	data := map[string]any{
		"name": playerName,
		"game": gameName,
		gameName: values,
	}
	out, err := yaml.Marshal(data)
	if err != nil {
		return "", err
	}
	return string(out), nil
}

// ConfigureRequest carries the parameters for a session configure call.
type ConfigureRequest struct {
	SessionID string
	Slots     []ConfigureSlotInput
}

// ConfigureSlotResult is the per-slot validation result from ConfigureSession.
type ConfigureSlotResult struct {
	PlayerName string
	Errors     []string
}

// ConfigureResult is returned by ConfigureSession.
type ConfigureResult struct {
	Valid bool
	Slots []ConfigureSlotResult
}

// parsePlayerName extracts the value of the top-level "name:" field from an Archipelago YAML.
// Returns an empty string if the field is absent.
func parsePlayerName(yamlStr string) string {
	for _, line := range strings.Split(yamlStr, "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "name:") {
			name := strings.TrimSpace(strings.TrimPrefix(trimmed, "name:"))
			name = strings.Trim(name, "\"'")
			return name
		}
	}
	return ""
}

// ConfigureSession validates slots, then (if all valid) uploads player YAMLs +
// manifest to Minio and upserts the session as "draft" in the DB.
// Returns ErrStorageNotConfigured if Minio is not wired up.
// Returns ErrAlreadyInProgress if the session is generating, launching, or running.
func (s *Service) ConfigureSession(ctx context.Context, req ConfigureRequest) (*ConfigureResult, error) {
	if s.storage == nil {
		return nil, ErrStorageNotConfigured
	}

	existing, err := s.db.GetSession(req.SessionID)
	if err != nil {
		return nil, fmt.Errorf("get session: %w", err)
	}
	if existing != nil {
		switch existing.Status {
		case "generating", "launching", "running":
			return nil, ErrAlreadyInProgress
		}
	}

	type slotState struct {
		playerName string
		yaml       string
		errors     []string
	}
	states := make([]slotState, len(req.Slots))
	valid := true

	for i, slot := range req.Slots {
		var errs []string
		var playerName, playerYaml string

		if slot.Options != nil {
			// Options mode: look up game name from storage and build YAML.
			playerName = slot.Options.PlayerName
			if playerName == "" {
				errs = append(errs, "options.playerName est requis.")
			}
			if slot.ApworldHash == "" {
				errs = append(errs, "apworldHash est requis.")
			} else {
				exists, err := s.storage.ApworldExists(ctx, slot.ApworldHash)
				if err != nil {
					return nil, fmt.Errorf("check apworld for player %q: %w", playerName, err)
				}
				if !exists {
					errs = append(errs, "L'apworld est introuvable dans le stockage.")
				} else {
					meta, err := s.storage.GetApworldMeta(ctx, slot.ApworldHash)
					if err != nil {
						return nil, fmt.Errorf("get apworld meta for player %q: %w", playerName, err)
					}
					if len(slot.Options.Values) > 0 {
						tmplData, found, err := s.storage.DownloadApworldTemplate(ctx, slot.ApworldHash)
						if err != nil {
							return nil, fmt.Errorf("download template for player %q: %w", playerName, err)
						}
						if found {
							validKeys := apworldOptionKeys(tmplData)
							for k := range slot.Options.Values {
								if !validKeys[k] {
									errs = append(errs, fmt.Sprintf("Option inconnue : '%s'.", k))
								}
							}
						}
					}
					if playerName != "" && len(errs) == 0 {
						builtYaml, err := buildPlayerYaml(playerName, meta.Game, slot.Options.Values)
						if err != nil {
							return nil, fmt.Errorf("build player yaml for player %q: %w", playerName, err)
						}
						playerYaml = builtYaml
					}
				}
			}
		} else {
			// YAML mode (existing behaviour).
			playerName = parsePlayerName(slot.PlayerYaml)
			playerYaml = slot.PlayerYaml
			if playerName == "" {
				errs = append(errs, "Le YAML ne contient pas de champ 'name'.")
			}
			if strings.TrimSpace(slot.PlayerYaml) == "" {
				errs = append(errs, "playerYaml est requis.")
			}
			if slot.ApworldHash == "" {
				errs = append(errs, "apworldHash est requis.")
			} else {
				exists, err := s.storage.ApworldExists(ctx, slot.ApworldHash)
				if err != nil {
					return nil, fmt.Errorf("check apworld for player %q: %w", playerName, err)
				}
				if !exists {
					errs = append(errs, "L'apworld est introuvable dans le stockage.")
				}
			}
		}

		if len(errs) > 0 {
			valid = false
		}
		states[i] = slotState{playerName: playerName, yaml: playerYaml, errors: errs}
	}

	results := make([]ConfigureSlotResult, len(req.Slots))
	for i, st := range states {
		errs := st.errors
		if errs == nil {
			errs = []string{}
		}
		results[i] = ConfigureSlotResult{PlayerName: st.playerName, Errors: errs}
	}

	if !valid {
		return &ConfigureResult{Valid: false, Slots: results}, nil
	}

	for i := range req.Slots {
		filename := states[i].playerName + ".yaml"
		if err := s.storage.UploadSessionYaml(ctx, req.SessionID, filename, []byte(states[i].yaml)); err != nil {
			return nil, fmt.Errorf("upload yaml for player %q: %w", states[i].playerName, err)
		}
	}

	seen := map[string]bool{}
	refs := make([]storage.ApworldRef, 0, len(req.Slots))
	for _, slot := range req.Slots {
		if seen[slot.ApworldHash] {
			continue
		}
		seen[slot.ApworldHash] = true
		refs = append(refs, storage.ApworldRef{
			Hash:     slot.ApworldHash,
			Filename: slot.ApworldHash + ".apworld",
		})
	}
	if err := s.storage.UploadManifest(ctx, req.SessionID, &storage.Manifest{Apworlds: refs}); err != nil {
		return nil, fmt.Errorf("upload manifest: %w", err)
	}

	now := time.Now().UTC()
	if existing == nil {
		if err := s.db.InsertSession(&db.Session{
			SessionID: req.SessionID,
			Status:    "draft",
			CreatedAt: now,
			UpdatedAt: now,
		}); err != nil {
			return nil, fmt.Errorf("insert session: %w", err)
		}
	} else {
		if err := s.db.UpdateSessionStatus(req.SessionID, "draft", nil); err != nil {
			return nil, fmt.Errorf("update session to draft: %w", err)
		}
	}

	return &ConfigureResult{Valid: true, Slots: results}, nil
}
