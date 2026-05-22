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

// Manifest is stored at sessions/{sessionId}/manifest.json by the Symfony API.
type Manifest struct {
	Apworlds []ApworldRef `json:"apworlds"`
}

func New(cfg Config) (*Client, error) {
	mc, err := minio.New(cfg.Endpoint, &minio.Options{
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

// UploadApworld stores an apworld binary in the apworlds bucket under its hash key.
func (c *Client) UploadApworld(ctx context.Context, hash string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketApworlds, hash,
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "application/octet-stream"})
	return err
}

// UploadApworldTemplate stores a YAML template for an apworld.
func (c *Client) UploadApworldTemplate(ctx context.Context, hash string, data []byte) error {
	_, err := c.mc.PutObject(ctx, c.cfg.BucketApworlds, hash+".yaml-template",
		bytes.NewReader(data), int64(len(data)),
		minio.PutObjectOptions{ContentType: "text/yaml"})
	return err
}

// DownloadApworldTemplate downloads the default YAML template for an apworld by its hash.
// Returns (nil, false, nil) if no template has been stored yet.
func (c *Client) DownloadApworldTemplate(ctx context.Context, hash string) ([]byte, bool, error) {
	obj, err := c.mc.GetObject(ctx, c.cfg.BucketApworlds, hash+".yaml-template", minio.GetObjectOptions{})
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
