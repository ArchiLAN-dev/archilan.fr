package api

// ContainerResponse represents a managed Bridge container.
type ContainerResponse struct {
	SessionID   string  `json:"sessionId"`
	Port        int     `json:"port"`
	Status      string  `json:"status"` // starting | running | stopped | crashed
	ContainerID *string `json:"containerId,omitempty"`
	Image       string  `json:"image"`
	CreatedAt   string  `json:"createdAt"`
	UpdatedAt   string  `json:"updatedAt"`
}

// ContainersResponse wraps a list of containers.
type ContainersResponse struct {
	Containers []ContainerResponse `json:"containers"`
}

// CreateContainerRequest is the body for POST /containers.
type CreateContainerRequest struct {
	SessionID      string `json:"sessionId"      example:"weekly-run-2026-05-20"`
	AdminPassword  string `json:"adminPassword"  example:"s3cr3t"` // required
	ServerPassword string `json:"serverPassword" example:""`        // optional — leave empty for open games
}

// CreateContainerResponse is returned on successful container creation.
type CreateContainerResponse struct {
	SessionID string `json:"sessionId" example:"weekly-run-2026-05-20"`
	Port      int    `json:"port"      example:"25000"`
	Status    string `json:"status"    example:"starting"`
}

// TemplateOption is a parsed game-specific option from an Archipelago YAML template.
//
// Type values:
//   - "range"   — numeric range; DefaultValue is int, RangeMin/RangeMax set.
//   - "choice"  — enumerated string; DefaultValue is string, ValidValues lists choices.
//   - "toggle"  — boolean flag; DefaultValue is bool.
//   - "text"    — free-form string; DefaultValue is nil or string.
//   - "weights" — each item in ValidValues receives an independent weight (0–100);
//     DefaultValue is map[string]int of item → default weight.
type TemplateOption struct {
	Key          string         `json:"key"`
	Description  string         `json:"description"`
	Type         string         `json:"type"`
	DefaultValue any            `json:"defaultValue"`
	ValidValues  []string       `json:"validValues,omitempty"`
	Weights      map[string]int `json:"weights,omitempty"`
	RangeMin     *int           `json:"rangeMin,omitempty"`
	RangeMax     *int           `json:"rangeMax,omitempty"`
}

// ApworldEntry represents a single uploaded apworld with its game metadata.
type ApworldEntry struct {
	Hash string `json:"hash"`
	Game string `json:"game"`
}

// ApworldListResponse is returned by GET /apworlds.
type ApworldListResponse struct {
	Apworlds []ApworldEntry `json:"apworlds"`
}

// ApworldOptionsResponse is returned by GET /apworlds/{hash}/options.
type ApworldOptionsResponse struct {
	Options []TemplateOption `json:"options"`
}

// UploadApworldResponse is returned on successful apworld upload.
type UploadApworldResponse struct {
	Hash    string           `json:"hash"    example:"0fd8936279e053df96110fcb7898447a9fb8655343b8f26c22108d79a73b4e21"`
	Options []TemplateOption `json:"options"`
}

// ErrorResponse is returned on error.
type ErrorResponse struct {
	Error string `json:"error" example:"session not found"`
}

// HealthResponse is returned by GET /health.
type HealthResponse struct {
	Status string `json:"status" example:"ok"`
}

// SessionResponse represents a managed Archipelago session.
type SessionResponse struct {
	SessionID      string  `json:"sessionId"`
	Status         string  `json:"status"`
	BridgePort     *int    `json:"bridgePort,omitempty"`
	APPort         *int    `json:"apPort,omitempty"`
	ServerPassword *string `json:"serverPassword,omitempty"`
	OutputFile     *string `json:"outputFile,omitempty"`
	CreatedAt      string  `json:"createdAt"`
	UpdatedAt      string  `json:"updatedAt"`
}

// GenerateSessionRequest is the body for POST /sessions/{sessionId}/generate.
type GenerateSessionRequest struct {
	AdminPassword string `json:"adminPassword"`
	Seed          string `json:"seed,omitempty"`
}

// LaunchSessionRequest is the body for POST /sessions/{sessionId}/launch and /launch-from-file.
type LaunchSessionRequest struct {
	ServerPassword string `json:"serverPassword,omitempty"`
	AdminPassword  string `json:"adminPassword"`
}

// SlotOption is a randomizer option with required flag and current/default values.
type SlotOption struct {
	Key          string `json:"key"`
	Required     bool   `json:"required"`
	CurrentValue any    `json:"currentValue"`
	DefaultValue any    `json:"defaultValue"`
}

// PreflightSlot is one slot entry in a preflight request.
// If ApworldStorageKey is non-empty it is a custom apworld slot; otherwise it is a bundled game slot.
type PreflightSlot struct {
	SlotID              string       `json:"slotId"`
	PlayerName          string       `json:"playerName"`
	ArchipelagoGameName string       `json:"archipelagoGameName,omitempty"`
	Options             []SlotOption `json:"options,omitempty"`
	ApworldStorageKey   string       `json:"apworldStorageKey,omitempty"`
	PlayerYaml          string       `json:"playerYaml,omitempty"`
}

// PreflightRequest is the body for POST /sessions/{sessionId}/preflight.
type PreflightRequest struct {
	Slots []PreflightSlot `json:"slots"`
}

// PreflightSlotResult is the validation result for a single slot.
type PreflightSlotResult struct {
	SlotID       string   `json:"slotId"`
	ProposedName string   `json:"proposedName"`
	Errors       []string `json:"errors"`
}

// PreflightResponse is returned by POST /sessions/{sessionId}/preflight.
type PreflightResponse struct {
	Valid bool                  `json:"valid"`
	Slots []PreflightSlotResult `json:"slots"`
}

// SlotOptionsPayload carries structured slot options for server-side YAML generation.
// Values may be scalars (string, int/float, bool) or nested maps for weights options.
type SlotOptionsPayload struct {
	PlayerName string         `json:"playerName"`
	Values     map[string]any `json:"values"`
}

// ConfigureSlotEntry is one slot entry in a configure request.
// Either PlayerYaml or Options must be set; Options takes priority when both are present.
type ConfigureSlotEntry struct {
	ApworldHash string              `json:"apworldHash"`
	PlayerYaml  string              `json:"playerYaml,omitempty"`
	Options     *SlotOptionsPayload `json:"options,omitempty"`
}

// ConfigureSessionRequest is the body for POST /sessions/{sessionId}/configure.
type ConfigureSessionRequest struct {
	Slots []ConfigureSlotEntry `json:"slots"`
}

// ConfigureSlotResponse is the per-slot result returned by POST /sessions/{sessionId}/configure.
type ConfigureSlotResponse struct {
	PlayerName string   `json:"playerName"`
	Errors     []string `json:"errors"`
}

// ConfigureResponse is returned by POST /sessions/{sessionId}/configure.
type ConfigureResponse struct {
	Valid bool                    `json:"valid"`
	Slots []ConfigureSlotResponse `json:"slots"`
}
