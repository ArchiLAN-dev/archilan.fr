package storage

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"strings"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

type Config struct {
	Endpoint       string
	AccessKey      string
	SecretKey      string
	UseSSL         bool
	BucketApworlds string
	BucketSessions string
}

type Client struct {
	mc  *minio.Client
	cfg Config
}

// ApworldRef identifies an apworld file by its content hash and original filename.
type ApworldRef struct {
	Hash     string `json:"hash"`
	Filename string `json:"filename"`
}

// ApworldMeta holds the game name for an uploaded apworld.
type ApworldMeta struct {
	Hash string `json:"hash"`
	Game string `json:"game"`
}

// Manifest is stored at sessions/{sessionId}/manifest.json by the Symfony API.
type Manifest struct {
	Apworlds []ApworldRef `json:"apworlds"`
}

func New(cfg Config) (*Client, error) {
	endpoint := cfg.Endpoint
	if idx := strings.Index(endpoint, "://"); idx >= 0 {
		endpoint = endpoint[idx+3:]
	}
	endpoint = strings.TrimRight(endpoint, "/")

	mc, err := minio.New(endpoint, &minio.Options{
		Creds:  credentials.NewStaticV4(cfg.AccessKey, cfg.SecretKey, ""),
		Secure: cfg.UseSSL,
	})
	if err != nil {
		return nil, err
	}
	return &Client{mc: mc, cfg: cfg}, nil
}

// ReadManifest fetches sessions/{sessionId}/manifest.json.
// Returns an empty manifest (no error) if the file does not exist.
func (c *Client) ReadManifest(ctx context.Context, sessionID string) (*Manifest, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketSessions, sessionID+"/manifest.json", minio.GetObjectOptions{})
	if err != nil {
		return nil, err
	}
	defer obj.Close()

	var m Manifest
	if err := json.NewDecoder(obj).Decode(&m); err != nil {
		if isNotFound(err) {
			return &Manifest{}, nil
		}
		return nil, err
	}
	return &m, nil
}

// DownloadApworld downloads an apworld by its SHA-256 hash from the apworlds bucket.
func (c *Client) DownloadApworld(ctx context.Context, hash string) ([]byte, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketApworlds, hash, minio.GetObjectOptions{})
	if err != nil {
		return nil, err
	}
	defer obj.Close()
	return io.ReadAll(obj)
}

func (c *Client) ListSessionYamls(ctx context.Context, sessionID string) ([]string, error) {
	prefix := sessionID + "/yamls/"
	var names []string
	for obj := range c.mc.ListObjects(ctx, c.cfg.BucketSessions, minio.ListObjectsOptions{Prefix: prefix, Recursive: true}) {
		if obj.Err != nil {
			return nil, obj.Err
		}
		names = append(names, strings.TrimPrefix(obj.Key, prefix))
	}
	return names, nil
}

func (c *Client) DownloadSessionYaml(ctx context.Context, sessionID, filename string) ([]byte, error) {
	key := sessionID + "/yamls/" + filename
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketSessions, key, minio.GetObjectOptions{})
	if err != nil {
		return nil, err
	}
	defer obj.Close()
	return io.ReadAll(obj)
}

// ApworldExists reports whether an apworld binary exists in the apworlds bucket.
func (c *Client) ApworldExists(ctx context.Context, hash string) (bool, error) {
	_, err := c.mc.StatObject(ctx, c.cfg.BucketApworlds, hash, minio.StatObjectOptions{})
	if err != nil {
		if isNotFound(err) {
			return false, nil
		}
		return false, err
	}
	return true, nil
}

// UploadSessionYaml stores a player YAML at sessions/{sessionId}/yamls/{filename}.
func (c *Client) UploadSessionYaml(ctx context.Context, sessionID, filename string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketSessions, sessionID+"/yamls/"+filename,
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "text/yaml"})
	return err
}

// UploadManifest stores the session manifest at sessions/{sessionId}/manifest.json.
func (c *Client) UploadManifest(ctx context.Context, sessionID string, manifest *Manifest) error {
	data, err := json.Marshal(manifest)
	if err != nil {
		return err
	}
	_, err = c.mc.PutObject(ctx, c.cfg.BucketSessions, sessionID+"/manifest.json",
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "application/json"})
	return err
}

// UploadApworld stores an apworld binary in the apworlds bucket under its hash key.
func (c *Client) UploadApworld(ctx context.Context, hash string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketApworlds, hash,
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "application/octet-stream"})
	return err
}

// UploadApworldTemplate stores a YAML template for an apworld.
func (c *Client) UploadApworldTemplate(ctx context.Context, hash string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketApworlds, hash+".yaml",
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "text/yaml"})
	return err
}

// UploadApworldMeta stores a {hash}.json metadata file in the apworlds bucket.
func (c *Client) UploadApworldMeta(ctx context.Context, meta ApworldMeta) error {
	data, err := json.Marshal(meta)
	if err != nil {
		return err
	}
	_, err = c.mc.PutObject(ctx, c.cfg.BucketApworlds, meta.Hash+".json",
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "application/json"})
	return err
}

// ListApworlds returns metadata for all apworlds that have a .json sidecar.
func (c *Client) ListApworlds(ctx context.Context) ([]ApworldMeta, error) {
	result := []ApworldMeta{}
	for obj := range c.mc.ListObjects(ctx, c.cfg.BucketApworlds, minio.ListObjectsOptions{Recursive: true}) {
		if obj.Err != nil {
			return nil, obj.Err
		}
		if !strings.HasSuffix(obj.Key, ".json") || strings.HasSuffix(obj.Key, ".types.json") {
			continue
		}
		meta, err := c.downloadApworldMeta(ctx, obj.Key)
		if err != nil {
			continue
		}
		result = append(result, meta)
	}
	return result, nil
}

// GetApworldMeta downloads the {hash}.json sidecar for an apworld.
func (c *Client) GetApworldMeta(ctx context.Context, hash string) (ApworldMeta, error) {
	return c.downloadApworldMeta(ctx, hash+".json")
}

func (c *Client) downloadApworldMeta(ctx context.Context, key string) (ApworldMeta, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketApworlds, key, minio.GetObjectOptions{})
	if err != nil {
		return ApworldMeta{}, err
	}
	defer obj.Close()
	var meta ApworldMeta
	if err := json.NewDecoder(obj).Decode(&meta); err != nil {
		return ApworldMeta{}, err
	}
	return meta, nil
}

// UploadApworldOptionTypes stores introspected option type data as {hash}.types.json.
func (c *Client) UploadApworldOptionTypes(ctx context.Context, hash string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketApworlds, hash+".types.json",
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "application/json"})
	return err
}

// DownloadApworldOptionTypes downloads introspected option type data for an apworld.
// Returns (nil, false, nil) if not stored yet.
func (c *Client) DownloadApworldOptionTypes(ctx context.Context, hash string) ([]byte, bool, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketApworlds, hash+".types.json", minio.GetObjectOptions{})
	if err != nil {
		return nil, false, err
	}
	defer obj.Close()
	data, err := io.ReadAll(obj)
	if err != nil {
		if isNotFound(err) {
			return nil, false, nil
		}
		return nil, false, err
	}
	return data, true, nil
}

// DownloadApworldTemplate downloads the default YAML template for an apworld by its hash.
// Returns (nil, false, nil) if no template has been stored yet.
func (c *Client) DownloadApworldTemplate(ctx context.Context, hash string) ([]byte, bool, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketApworlds, hash+".yaml", minio.GetObjectOptions{})
	if err != nil {
		return nil, false, err
	}
	defer obj.Close()
	data, err := io.ReadAll(obj)
	if err != nil {
		if isNotFound(err) {
			return nil, false, nil
		}
		return nil, false, err
	}
	return data, true, nil
}

func isNotFound(err error) bool {
	if err == nil {
		return false
	}
	resp := minio.ToErrorResponse(err)
	return resp.Code == "NoSuchKey" || resp.StatusCode == 404
}
