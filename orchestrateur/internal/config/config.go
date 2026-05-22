package config

import (
	"fmt"
	"os"
	"strconv"
)

type Config struct {
	Port           int
	APIKey         string
	PortRangeStart int
	PortRangeEnd   int
	DBPath         string
	DockerHost     string
	BridgeImage    string
	BridgeNetwork  string
	BridgeToken    string
	WebhookURL     string
	WebhookSecret  string
}

func Load() *Config {
	return &Config{
		Port:           envInt("PORT", 8000),
		APIKey:         envRequired("API_KEY"),
		PortRangeStart: envInt("PORT_RANGE_START", 25000),
		PortRangeEnd:   envInt("PORT_RANGE_END", 25099),
		DBPath:         env("DB_PATH", "/data/orchestrateur.db"),
		DockerHost:     env("DOCKER_HOST", "unix:///var/run/docker.sock"),
		BridgeImage:    env("BRIDGE_IMAGE", "archilan-bridge:latest"),
		BridgeNetwork:  env("BRIDGE_NETWORK", "archilan_default"),
		BridgeToken:    envRequired("BRIDGE_TOKEN"),
		WebhookURL:     env("WEBHOOK_URL", ""),
		WebhookSecret:  env("WEBHOOK_SECRET", ""),
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
