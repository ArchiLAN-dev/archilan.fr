package webhook

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"net/http"
	"time"
)

type Sender struct {
	url    string
	secret string
	log    *slog.Logger
	client *http.Client
}

type Payload struct {
	Event      string    `json:"event"`
	SessionID  string    `json:"sessionId"`
	Port       int       `json:"port,omitempty"`       // AP server port (players connect here)
	BridgePort int       `json:"bridgePort,omitempty"` // Bridge container port (internal)
	Error      string    `json:"error,omitempty"`
	Timestamp  time.Time `json:"timestamp"`
}

func New(url, secret string, log *slog.Logger) *Sender {
	return &Sender{
		url:    url,
		secret: secret,
		log:    log,
		client: &http.Client{Timeout: 10 * time.Second},
	}
}

func (s *Sender) Send(ctx context.Context, p Payload) {
	if s.url == "" {
		return
	}
	p.Timestamp = time.Now().UTC()

	body, err := json.Marshal(p)
	if err != nil {
		s.log.Error("webhook: marshal error", "err", err)
		return
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.url, bytes.NewReader(body))
	if err != nil {
		s.log.Error("webhook: request build error", "err", err)
		return
	}
	req.Header.Set("Content-Type", "application/json")
	if s.secret != "" {
		req.Header.Set("X-Signature-256", sign(body, s.secret))
	}

	resp, err := s.client.Do(req)
	if err != nil {
		s.log.Error("webhook: send error", "err", err, "session_id", p.SessionID)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		s.log.Warn("webhook: non-2xx response", "status", resp.StatusCode, "session_id", p.SessionID)
	}
}

func sign(body []byte, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(body)
	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}
