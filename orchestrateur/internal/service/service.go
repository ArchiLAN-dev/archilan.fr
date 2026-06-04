package service

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"time"

	"archilan.fr/orchestrateur/internal/config"
	"archilan.fr/orchestrateur/internal/db"
	"archilan.fr/orchestrateur/internal/docker"
	"archilan.fr/orchestrateur/internal/portpool"
	"archilan.fr/orchestrateur/internal/storage"
	"archilan.fr/orchestrateur/internal/webhook"
)

var (
	ErrNotFound             = errors.New("session not found")
	ErrAlreadyExists        = errors.New("session already exists")
	ErrPortExhausted        = portpool.ErrExhausted
	ErrStorageNotConfigured = errors.New("storage not configured")
)

type Service struct {
	db      *db.DB
	docker  *docker.Client
	pool    *portpool.Pool
	webhook *webhook.Sender
	storage *storage.Client // nil if Minio not configured
	cfg     *config.Config
	log     *slog.Logger
}

func New(
	database *db.DB,
	dockerClient *docker.Client,
	pool *portpool.Pool,
	webhookSender *webhook.Sender,
	storageCl *storage.Client,
	cfg *config.Config,
	log *slog.Logger,
) *Service {
	return &Service{
		db:      database,
		docker:  dockerClient,
		pool:    pool,
		webhook: webhookSender,
		storage: storageCl,
		cfg:     cfg,
		log:     log,
	}
}

// UploadApworld hashes the binary, stores it in Minio, generates the YAML template via
// a one-shot Archipelago container, stores the template, and returns both.
func (s *Service) UploadApworld(ctx context.Context, data []byte) (hash string, yamlData []byte, err error) {
	if s.storage == nil {
		return "", nil, ErrStorageNotConfigured
	}

	sum := sha256.Sum256(data)
	hash = fmt.Sprintf("%x", sum)

	if err = s.storage.UploadApworld(ctx, hash, data); err != nil {
		return hash, nil, fmt.Errorf("upload apworld: %w", err)
	}

	yamlData, err = s.docker.GenerateTemplate(ctx, data, hash)
	if err != nil {
		// Template generation failure is non-fatal: some apworlds have Python bugs
		// that prevent template generation but are otherwise valid for generation.
		// We store an empty template and continue so the apworld can still be used.
		s.log.Warn("template generation failed, continuing without template", "hash", hash, "err", err)
		yamlData = []byte{}
	}

	if len(yamlData) > 0 {
		if err = s.storage.UploadApworldTemplate(ctx, hash, yamlData); err != nil {
			return hash, yamlData, fmt.Errorf("upload template: %w", err)
		}
	}

	game := extractApworldGame(yamlData)
	if game == "" {
		game = extractGameFromZip(data)
	}
	if metaErr := s.storage.UploadApworldMeta(ctx, storage.ApworldMeta{
		Hash: hash,
		Game: game,
	}); metaErr != nil {
		s.log.Warn("failed to store apworld metadata", "hash", hash, "err", metaErr)
	}

	// Run option type introspection in background; failure is non-fatal.
	go func() {
		bgCtx := context.Background()
		typesJSON, intrErr := s.docker.IntrospectOptions(bgCtx, data, hash)
		if intrErr != nil {
			s.log.Warn("failed to introspect option types", "hash", hash, "err", intrErr)
			return
		}
		if storeErr := s.storage.UploadApworldOptionTypes(bgCtx, hash, typesJSON); storeErr != nil {
			s.log.Warn("failed to store option types", "hash", hash, "err", storeErr)
		}
	}()

	return hash, yamlData, nil
}

// OptionTypeOverride is the type classification for one option from Python introspection.
type OptionTypeOverride struct {
	Type           string         `json:"type"`
	DefaultWeights map[string]int `json:"defaultWeights,omitempty"`
}

// GetApworldOptionTypes returns introspected type overrides for an apworld's options.
// Returns nil (no error) if storage is not configured or the types file does not exist yet.
func (s *Service) GetApworldOptionTypes(ctx context.Context, hash string) map[string]OptionTypeOverride {
	if s.storage == nil {
		return nil
	}
	raw, found, err := s.storage.DownloadApworldOptionTypes(ctx, hash)
	if err != nil || !found {
		return nil
	}
	var parsed struct {
		Options map[string]OptionTypeOverride `json:"options"`
	}
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return nil
	}
	return parsed.Options
}

// ListApworlds returns metadata for all uploaded apworlds that have a .json sidecar.
func (s *Service) ListApworlds(ctx context.Context) ([]storage.ApworldMeta, error) {
	if s.storage == nil {
		return nil, ErrStorageNotConfigured
	}
	return s.storage.ListApworlds(ctx)
}

// extractApworldGame reads the game name from the `game:` line of a YAML template.
func extractApworldGame(yamlData []byte) string {
	for _, line := range strings.Split(string(yamlData), "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "game:") {
			parts := strings.SplitN(line, ":", 2)
			if len(parts) == 2 {
				return strings.TrimSpace(parts[1])
			}
		}
	}
	return ""
}

// extractGameFromZip reads the game name from archipelago.json inside the apworld ZIP.
// Falls back to the top-level package folder name if archipelago.json is absent or malformed.
func extractGameFromZip(data []byte) string {
	r, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return ""
	}

	var folderName string
	for _, f := range r.File {
		parts := strings.SplitN(f.Name, "/", 2)
		if len(parts) >= 1 && parts[0] != "" && folderName == "" {
			folderName = parts[0]
		}

		base := f.Name
		if idx := strings.LastIndex(f.Name, "/"); idx >= 0 {
			base = f.Name[idx+1:]
		}
		if base != "archipelago.json" {
			continue
		}

		rc, openErr := f.Open()
		if openErr != nil {
			continue
		}
		raw, readErr := io.ReadAll(rc)
		_ = rc.Close()
		if readErr != nil {
			continue
		}

		var meta struct {
			Game string `json:"game"`
			Name string `json:"name"`
		}
		if jsonErr := json.Unmarshal(raw, &meta); jsonErr != nil {
			continue
		}
		if meta.Game != "" {
			return meta.Game
		}
		if meta.Name != "" {
			return meta.Name
		}
	}

	return folderName
}

// GetApworldTemplate returns the default YAML template for an apworld.
// Returns ErrNotFound if Minio is not configured or no template is stored for this hash.
func (s *Service) GetApworldTemplate(ctx context.Context, hash string) ([]byte, error) {
	if s.storage == nil {
		return nil, ErrNotFound
	}
	data, found, err := s.storage.DownloadApworldTemplate(ctx, hash)
	if err != nil {
		return nil, err
	}
	if !found {
		return nil, ErrNotFound
	}
	return data, nil
}

// RecoverFromDB restores the port pool from persisted container and session records.
func (s *Service) RecoverFromDB(ctx context.Context) error {
	// Recover legacy Bridge-only container ports
	ports, err := s.db.AllPorts()
	if err != nil {
		return fmt.Errorf("recover containers: %w", err)
	}
	for port, sessionID := range ports {
		s.pool.Reserve(port, sessionID)
		s.log.Info("recovered container from db", "session_id", sessionID, "port", port)
	}

	// Recover session ports
	sessionPorts, err := s.db.AllSessionPorts()
	if err != nil {
		return fmt.Errorf("recover sessions: %w", err)
	}
	for port, sessionID := range sessionPorts {
		s.pool.Reserve(port, sessionID)
		s.log.Info("recovered session port from db", "session_id", sessionID, "port", port)
	}

	return nil
}

type CreateRequest struct {
	SessionID      string
	ServerPassword string
	AdminPassword  string
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

	go s.startContainer(req.SessionID, port, req.ServerPassword, req.AdminPassword)

	return port, nil
}

func (s *Service) startContainer(sessionID string, port int, serverPassword, adminPassword string) {
	ctx := context.Background()

	// Build tar from Minio files if storage is configured.
	var tarData io.Reader
	if s.storage != nil {
		tr, n, err := s.buildDataTar(ctx, sessionID)
		if err != nil {
			s.log.Error("build data tar failed", "session_id", sessionID, "err", err)
			_ = s.db.UpdateStatus(sessionID, "crashed", nil)
			s.webhook.Send(ctx, webhook.Payload{
				Event:     "container.crashed",
				SessionID: sessionID,
				Port:      port,
				Error:     err.Error(),
			})
			return
		}
		if n > 0 {
			tarData = tr
		}
	}

	containerID, err := s.docker.Create(ctx, docker.CreateConfig{
		SessionID:      sessionID,
		Port:           port,
		BridgeToken:    s.cfg.BridgeToken,
		APImage:        s.cfg.APImage,
		ServerPassword: serverPassword,
		AdminPassword:  adminPassword,
	})
	if err != nil {
		s.log.Error("container create failed", "session_id", sessionID, "err", err)
		_ = s.db.UpdateStatus(sessionID, "crashed", nil)
		s.webhook.Send(ctx, webhook.Payload{
			Event:     "container.crashed",
			SessionID: sessionID,
			Port:      port,
			Error:     err.Error(),
		})
		return
	}

	if tarData != nil {
		if err := s.docker.PutArchive(ctx, containerID, tarData); err != nil {
			s.log.Error("put archive failed", "session_id", sessionID, "err", err)
			_ = s.db.UpdateStatus(sessionID, "crashed", nil)
			s.webhook.Send(ctx, webhook.Payload{
				Event:     "container.crashed",
				SessionID: sessionID,
				Port:      port,
				Error:     err.Error(),
			})
			return
		}
	}

	if err := s.docker.Start(ctx, containerID, sessionID, port); err != nil {
		s.log.Error("container start failed", "session_id", sessionID, "err", err)
		_ = s.db.UpdateStatus(sessionID, "crashed", nil)
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

// buildDataTar downloads apworld and YAML files from Minio and returns a tar archive
// ready to be uploaded into the container at /data. Returns the number of files written.
// All entries are owned by UID/GID 1000 (bridge user) so the bridge process can write to them.
func (s *Service) buildDataTar(ctx context.Context, sessionID string) (*bytes.Buffer, int, error) {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	written := 0

	// Create directories with bridge user ownership so the bridge process can write to them.
	for _, dir := range []string{"worlds/", "yamls/", "saves/", "output/"} {
		if err := tw.WriteHeader(&tar.Header{
			Typeflag: tar.TypeDir,
			Name:     dir,
			Mode:     0755,
			Uid:      1000,
			Gid:      1000,
		}); err != nil {
			return nil, 0, err
		}
	}

	manifest, err := s.storage.ReadManifest(ctx, sessionID)
	if err != nil {
		return nil, 0, fmt.Errorf("read manifest: %w", err)
	}

	for _, ref := range manifest.Apworlds {
		data, err := s.storage.DownloadApworld(ctx, ref.Hash)
		if err != nil {
			return nil, 0, fmt.Errorf("download apworld %s: %w", ref.Filename, err)
		}
		if err := tw.WriteHeader(&tar.Header{
			Name: "worlds/" + ref.Filename, Mode: 0644, Size: int64(len(data)), Uid: 1000, Gid: 1000,
		}); err != nil {
			return nil, 0, err
		}
		if _, err := tw.Write(data); err != nil {
			return nil, 0, err
		}
		written++
	}

	yamls, err := s.storage.ListSessionYamls(ctx, sessionID)
	if err != nil {
		return nil, 0, fmt.Errorf("list yamls: %w", err)
	}
	for _, name := range yamls {
		data, err := s.storage.DownloadSessionYaml(ctx, sessionID, name)
		if err != nil {
			return nil, 0, fmt.Errorf("download yaml %s: %w", name, err)
		}
		if err := tw.WriteHeader(&tar.Header{
			Name: "yamls/" + name, Mode: 0644, Size: int64(len(data)), Uid: 1000, Gid: 1000,
		}); err != nil {
			return nil, 0, err
		}
		if _, err := tw.Write(data); err != nil {
			return nil, 0, err
		}
		written++
	}

	// Inject the Bridge observer slot so the bridge WS client can connect to the AP server.
	// The Archipelago game type is a TextOnly spectator slot; it needs explicit game options
	// so that Generate.roll_settings does not raise "No game options found".
	bridgeYaml := []byte("name: Bridge\ngame: Archipelago\nArchipelago:\n  progression_balancing: 0\n  accessibility: items\n")
	if err := tw.WriteHeader(&tar.Header{
		Name: "yamls/_bridge_observer.yaml", Mode: 0644, Size: int64(len(bridgeYaml)), Uid: 1000, Gid: 1000,
	}); err != nil {
		return nil, 0, err
	}
	if _, err := tw.Write(bridgeYaml); err != nil {
		return nil, 0, err
	}
	written++

	if err := tw.Close(); err != nil {
		return nil, 0, err
	}
	return &buf, written, nil
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

func (s *Service) Get(ctx context.Context, sessionID string) (*db.Container, error) {
	c, err := s.db.Get(sessionID)
	if err != nil {
		return nil, err
	}
	if c == nil {
		return nil, ErrNotFound
	}
	s.syncStatus(ctx, c)
	return c, nil
}

func (s *Service) List() ([]*db.Container, error) {
	return s.db.List()
}

// syncStatus queries Docker for the real state of a single container and updates the record in-place.
func (s *Service) syncStatus(ctx context.Context, c *db.Container) {
	if c.ContainerID == nil {
		return
	}
	info, err := s.docker.Inspect(ctx, *c.ContainerID)
	if err != nil {
		s.log.Warn("inspect failed", "session_id", c.SessionID, "err", err)
		return
	}
	var live string
	if info == nil {
		live = "crashed"
	} else {
		live = dockerStatusToApp(*info)
	}
	if live != c.Status {
		c.Status = live
		_ = s.db.UpdateStatus(c.SessionID, live, c.ContainerID)
	}
}

func dockerStatusToApp(info docker.ContainerStatus) string {
	switch info.Status {
	case "running", "restarting":
		return "running"
	case "created":
		return "starting"
	case "exited", "dead":
		if info.ExitCode == 0 {
			return "stopped"
		}
		return "crashed"
	default:
		return info.Status
	}
}
