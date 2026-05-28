package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Port              int
	APIKey            string
	PortRangeStart    int
	PortRangeEnd      int
	APServerPortOffset int // host_port = bridge_port + APServerPortOffset
	DBPath         string
	DockerHost     string
	BridgeImage    string
	BridgeNetwork  string
	BridgeToken    string
	APImage        string
	WebhookURL     string
	WebhookSecret  string
	CentralAPIURL    string
	CentralAPISecret string
	CORSOrigins    []string
	MinioEndpoint       string
	MinioAccessKey      string
	MinioSecretKey      string
	MinioUseSSL         bool
	MinioBucketApworlds string
	MinioBucketSessions string

	GenerationTimeout time.Duration // default 10min
	LaunchTimeout     time.Duration // default 2min
	SweeperInterval   time.Duration // default 30s
}

func Load() *Config {
	return &Config{
		Port:               envInt("PORT", 8000),
		APIKey:             envRequired("API_KEY"),
		PortRangeStart:     envInt("PORT_RANGE_START", 25000),
		PortRangeEnd:       envInt("PORT_RANGE_END", 25099),
		APServerPortOffset: envInt("AP_SERVER_PORT_OFFSET", 10000),
		DBPath:         env("DB_PATH", "/data/orchestrateur.db"),
		DockerHost:     env("DOCKER_HOST", "unix:///var/run/docker.sock"),
		BridgeImage:    env("BRIDGE_IMAGE", "archilan-bridge:latest"),
		BridgeNetwork:  env("BRIDGE_NETWORK", "archilan_default"),
		BridgeToken:    envRequired("BRIDGE_TOKEN"),
		APImage:        env("AP_IMAGE", "archipelago:latest"),
		WebhookURL:     env("WEBHOOK_URL", ""),
		WebhookSecret:  env("WEBHOOK_SECRET", ""),
		CentralAPIURL:    env("CENTRAL_API_URL", ""),
		CentralAPISecret: env("CENTRAL_API_SECRET", ""),
		CORSOrigins:    envList("CORS_ORIGINS", []string{"*"}),
		MinioEndpoint:       env("MINIO_ENDPOINT", ""),
		MinioAccessKey:      env("MINIO_ACCESS_KEY", ""),
		MinioSecretKey:      env("MINIO_SECRET_KEY", ""),
		MinioUseSSL:         envBool("MINIO_USE_SSL", false),
		MinioBucketApworlds: env("MINIO_BUCKET_APWORLDS", "apworlds"),
		MinioBucketSessions: env("MINIO_BUCKET_SESSIONS", "sessions"),

		GenerationTimeout: envDuration("GENERATION_TIMEOUT", 600),
		LaunchTimeout:     envDuration("LAUNCH_TIMEOUT", 120),
		SweeperInterval:   envDuration("SWEEPER_INTERVAL", 30),
	}
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envRequired(key string) string {
	v := os.Getenv(key)
	if v == "" {
		panic(fmt.Sprintf("required env var %s is not set", key))
	}
	return v
}

func envInt(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return fallback
}

func envList(key string, fallback []string) []string {
	if v := os.Getenv(key); v != "" {
		return strings.Split(v, ",")
	}
	return fallback
}

func envBool(key string, fallback bool) bool {
	if v := os.Getenv(key); v != "" {
		return v == "true" || v == "1"
	}
	return fallback
}

func envDuration(key string, fallbackSecs int) time.Duration {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return time.Duration(n) * time.Second
		}
	}
	return time.Duration(fallbackSecs) * time.Second
}
