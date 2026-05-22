package docker

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"strings"

	"archilan.fr/orchestrateur/internal/config"
)

const apiVersion = "v1.47"
const managedLabel = "archilan.managed"
const sessionLabel = "archilan.session_id"

type Client struct {
	http *http.Client
	cfg  *config.Config
	log  *slog.Logger
}

func New(cfg *config.Config, log *slog.Logger) (*Client, error) {
	socketPath := strings.TrimPrefix(cfg.DockerHost, "unix://")
	transport := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{}).DialContext(ctx, "unix", socketPath)
		},
	}
	return &Client{
		http: &http.Client{Transport: transport},
		cfg:  cfg,
		log:  log,
	}, nil
}

func (c *Client) url(path string) string {
	return fmt.Sprintf("http://localhost/%s%s", apiVersion, path)
}

func (c *Client) do(ctx context.Context, method, path string, body any) (*http.Response, error) {
	var r io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.url(path), r)
	if err != nil {
		return nil, err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	return c.http.Do(req)
}

// ---------------------------------------------------------------------------
// Container lifecycle
// ---------------------------------------------------------------------------

type createBody struct {
	Image        string            `json:"Image"`
	Env          []string          `json:"Env"`
	Labels       map[string]string `json:"Labels"`
	ExposedPorts map[string]struct{} `json:"ExposedPorts"`
	HostConfig   hostConfig        `json:"HostConfig"`
	NetworkingConfig networkingConfig `json:"NetworkingConfig"`
}

type hostConfig struct {
	PortBindings  map[string][]portBinding `json:"PortBindings"`
	RestartPolicy restartPolicy            `json:"RestartPolicy"`
	Binds         []string                 `json:"Binds"`
}

type portBinding struct {
	HostIP   string `json:"HostIp"`
	HostPort string `json:"HostPort"`
}

type restartPolicy struct {
	Name string `json:"Name"`
}

type networkingConfig struct {
	EndpointsConfig map[string]struct{} `json:"EndpointsConfig"`
}

type createResponse struct {
	ID string `json:"Id"`
}

type CreateConfig struct {
	SessionID   string
	Port        int
	BridgeToken string
}

func (c *Client) CreateAndStart(ctx context.Context, cfg CreateConfig) (string, error) {
	body := createBody{
		Image: c.cfg.BridgeImage,
		Env: []string{
			fmt.Sprintf("SESSION_ID=%s", cfg.SessionID),
			fmt.Sprintf("INTERNAL_TOKEN=%s", cfg.BridgeToken),
			"AP_WS_URL=ws://localhost:38281",
			"REST_PORT=5000",
			"SAVE_DIR=/data/saves",
			"AP_WORLDS_DIR=/data/worlds",
			"AP_YAMLS_DIR=/data/yamls",
			"AP_OUTPUT_DIR=/data/output",
		},
		Labels: map[string]string{
			managedLabel: "true",
			sessionLabel: cfg.SessionID,
		},
		ExposedPorts: map[string]struct{}{"5000/tcp": {}},
		HostConfig: hostConfig{
			PortBindings: map[string][]portBinding{
				"5000/tcp": {{HostIP: "0.0.0.0", HostPort: fmt.Sprintf("%d", cfg.Port)}},
			},
			RestartPolicy: restartPolicy{Name: "unless-stopped"},
			Binds:         []string{fmt.Sprintf("archilan_session_%s:/data", cfg.SessionID)},
		},
		NetworkingConfig: networkingConfig{
			EndpointsConfig: map[string]struct{}{
				c.cfg.BridgeNetwork: {},
			},
		},
	}

	name := fmt.Sprintf("archilan-bridge-%s", cfg.SessionID)
	resp, err := c.do(ctx, http.MethodPost, fmt.Sprintf("/containers/create?name=%s", name), body)
	if err != nil {
		return "", fmt.Errorf("create: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusCreated {
		raw, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("create: status %d: %s", resp.StatusCode, raw)
	}

	var created createResponse
	if err := json.NewDecoder(resp.Body).Decode(&created); err != nil {
		return "", fmt.Errorf("create decode: %w", err)
	}

	startResp, err := c.do(ctx, http.MethodPost, fmt.Sprintf("/containers/%s/start", created.ID), nil)
	if err != nil {
		return created.ID, fmt.Errorf("start: %w", err)
	}
	defer startResp.Body.Close()

	if startResp.StatusCode != http.StatusNoContent {
		raw, _ := io.ReadAll(startResp.Body)
		return created.ID, fmt.Errorf("start: status %d: %s", startResp.StatusCode, raw)
	}

	c.log.Info("container started", "session_id", cfg.SessionID, "container_id", created.ID, "port", cfg.Port)
	return created.ID, nil
}

func (c *Client) Stop(ctx context.Context, containerID string) error {
	resp, err := c.do(ctx, http.MethodPost, fmt.Sprintf("/containers/%s/stop?t=10", containerID), nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

func (c *Client) Remove(ctx context.Context, containerID string) error {
	resp, err := c.do(ctx, http.MethodDelete, fmt.Sprintf("/containers/%s?force=true", containerID), nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

func (c *Client) Restart(ctx context.Context, containerID string) error {
	resp, err := c.do(ctx, http.MethodPost, fmt.Sprintf("/containers/%s/restart?t=10", containerID), nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

// ---------------------------------------------------------------------------
// Event stream
// ---------------------------------------------------------------------------

type EventType string

const (
	EventStart EventType = "start"
	EventDie   EventType = "die"
)

type Event struct {
	Type        EventType
	SessionID   string
	ContainerID string
	ExitCode    string
}

type dockerEvent struct {
	Action string `json:"Action"`
	Actor  struct {
		ID         string            `json:"ID"`
		Attributes map[string]string `json:"Attributes"`
	} `json:"Actor"`
}

func (c *Client) WatchEvents(ctx context.Context) (<-chan Event, <-chan error) {
	out := make(chan Event, 32)
	errc := make(chan error, 1)

	go func() {
		defer close(out)

		filters := fmt.Sprintf(`{"label":["%s=true"],"type":["container"],"event":["start","die"]}`, managedLabel)
		path := fmt.Sprintf("/events?filters=%s", filters)

		req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.url(path), nil)
		if err != nil {
			errc <- err
			return
		}

		resp, err := c.http.Do(req)
		if err != nil {
			errc <- err
			return
		}
		defer resp.Body.Close()

		scanner := bufio.NewScanner(resp.Body)
		for scanner.Scan() {
			var ev dockerEvent
			if err := json.Unmarshal(scanner.Bytes(), &ev); err != nil {
				continue
			}
			sessionID := ev.Actor.Attributes[sessionLabel]
			if sessionID == "" {
				continue
			}
			out <- Event{
				Type:        EventType(ev.Action),
				SessionID:   sessionID,
				ContainerID: ev.Actor.ID,
				ExitCode:    ev.Actor.Attributes["exitCode"],
			}
		}

		if err := scanner.Err(); err != nil && ctx.Err() == nil {
			errc <- err
		}
	}()

	return out, errc
}
