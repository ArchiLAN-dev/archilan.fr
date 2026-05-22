package db

import (
	"database/sql"
	"time"
)

// Session represents an Archipelago multiworld session managed by the orchestrator.
// State machine: pending → generating → generated → launching → running → stopped/crashed
type Session struct {
	SessionID         string
	Status            string
	BridgeContainerID *string
	APContainerID     *string
	BridgePort        *int
	APPort            *int
	ServerPassword    *string
	AdminPassword     *string
	OutputFile        *string
	GenerationJobID   *string
	StatusDeadline    *time.Time
	CreatedAt         time.Time
	UpdatedAt         time.Time
}

// InsertSession inserts a new session record.
func (db *DB) InsertSession(s *Session) error {
	_, err := db.Exec(`
		INSERT INTO sessions
		  (session_id, status, bridge_container_id, ap_container_id,
		   bridge_port, ap_port, server_password, admin_password,
		   output_file, generation_job_id, status_deadline, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		s.SessionID, s.Status, s.BridgeContainerID, s.APContainerID,
		s.BridgePort, s.APPort, s.ServerPassword, s.AdminPassword,
		s.OutputFile, s.GenerationJobID, s.StatusDeadline, s.CreatedAt, s.UpdatedAt,
	)
	return err
}

// GetSession returns the session with the given ID, or nil if not found.
func (db *DB) GetSession(sessionID string) (*Session, error) {
	row := db.QueryRow(`
		SELECT session_id, status, bridge_container_id, ap_container_id,
		       bridge_port, ap_port, server_password, admin_password,
		       output_file, generation_job_id, status_deadline, created_at, updated_at
		FROM sessions WHERE session_id = ?`, sessionID)
	return scanSession(row)
}

// ListSessions returns all sessions ordered by creation date descending.
func (db *DB) ListSessions() ([]*Session, error) {
	rows, err := db.Query(`
		SELECT session_id, status, bridge_container_id, ap_container_id,
		       bridge_port, ap_port, server_password, admin_password,
		       output_file, generation_job_id, status_deadline, created_at, updated_at
		FROM sessions ORDER BY created_at DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []*Session
	for rows.Next() {
		s, err := scanSession(rows)
		if err != nil {
			return nil, err
		}
		result = append(result, s)
	}
	return result, rows.Err()
}

// UpdateSessionStatus sets the status (and optionally a deadline) of a session.
func (db *DB) UpdateSessionStatus(sessionID, status string, deadline *time.Time) error {
	_, err := db.Exec(
		`UPDATE sessions SET status = ?, status_deadline = ?, updated_at = ? WHERE session_id = ?`,
		status, deadline, time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionGenerating transitions the session to the "generating" state and records the job ID.
func (db *DB) UpdateSessionGenerating(sessionID, jobID string, deadline time.Time) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'generating', generation_job_id = ?, status_deadline = ?, updated_at = ?
		WHERE session_id = ?`,
		jobID, deadline, time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionGenerated transitions the session to the "generated" state and stores the output file name.
func (db *DB) UpdateSessionGenerated(sessionID, outputFile string) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'generated', output_file = ?, status_deadline = NULL,
		                    generation_job_id = NULL, updated_at = ?
		WHERE session_id = ?`,
		outputFile, time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionLaunching transitions the session to the "launching" state.
func (db *DB) UpdateSessionLaunching(sessionID string, bridgePort, apPort int, serverPassword, adminPassword string, deadline time.Time) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'launching', bridge_port = ?, ap_port = ?,
		                    server_password = ?, admin_password = ?,
		                    status_deadline = ?, updated_at = ?
		WHERE session_id = ?`,
		bridgePort, apPort, serverPassword, adminPassword, deadline, time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionRunning transitions the session to the "running" state and stores container IDs.
func (db *DB) UpdateSessionRunning(sessionID, bridgeContainerID, apContainerID string) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'running', bridge_container_id = ?, ap_container_id = ?,
		                    status_deadline = NULL, updated_at = ?
		WHERE session_id = ?`,
		bridgeContainerID, apContainerID, time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionCrashed transitions the session to the "crashed" state.
func (db *DB) UpdateSessionCrashed(sessionID string) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'crashed', status_deadline = NULL, updated_at = ?
		WHERE session_id = ?`,
		time.Now().UTC(), sessionID,
	)
	return err
}

// UpdateSessionStopped transitions the session to the "stopped" state.
func (db *DB) UpdateSessionStopped(sessionID string) error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'stopped', status_deadline = NULL, updated_at = ?
		WHERE session_id = ?`,
		time.Now().UTC(), sessionID,
	)
	return err
}

// ExtendSessionDeadline updates the status_deadline for a session.
func (db *DB) ExtendSessionDeadline(sessionID string, deadline time.Time) error {
	_, err := db.Exec(
		`UPDATE sessions SET status_deadline = ?, updated_at = ? WHERE session_id = ?`,
		deadline, time.Now().UTC(), sessionID,
	)
	return err
}

// ListExpiredSessionsInTransit returns sessions stuck in generating/launching past their deadline.
func (db *DB) ListExpiredSessionsInTransit(now time.Time) ([]*Session, error) {
	rows, err := db.Query(`
		SELECT session_id, status, bridge_container_id, ap_container_id,
		       bridge_port, ap_port, server_password, admin_password,
		       output_file, generation_job_id, status_deadline, created_at, updated_at
		FROM sessions
		WHERE status IN ('generating', 'launching') AND status_deadline < ?`, now)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []*Session
	for rows.Next() {
		s, err := scanSession(rows)
		if err != nil {
			return nil, err
		}
		result = append(result, s)
	}
	return result, rows.Err()
}

// ListRunningSessionsForReconciliation returns all sessions with status = 'running'.
func (db *DB) ListRunningSessionsForReconciliation() ([]*Session, error) {
	rows, err := db.Query(`
		SELECT session_id, status, bridge_container_id, ap_container_id,
		       bridge_port, ap_port, server_password, admin_password,
		       output_file, generation_job_id, status_deadline, created_at, updated_at
		FROM sessions WHERE status = 'running'`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []*Session
	for rows.Next() {
		s, err := scanSession(rows)
		if err != nil {
			return nil, err
		}
		result = append(result, s)
	}
	return result, rows.Err()
}

// AllSessionPorts returns a map of bridge_port → session_id for all non-terminal sessions.
func (db *DB) AllSessionPorts() (map[int]string, error) {
	rows, err := db.Query(`
		SELECT bridge_port, session_id FROM sessions
		WHERE bridge_port IS NOT NULL AND status NOT IN ('stopped', 'crashed')`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	m := make(map[int]string)
	for rows.Next() {
		var port int
		var sessionID string
		if err := rows.Scan(&port, &sessionID); err != nil {
			return nil, err
		}
		m[port] = sessionID
	}
	return m, rows.Err()
}

// DeleteSession removes a session record entirely.
func (db *DB) DeleteSession(sessionID string) error {
	_, err := db.Exec(`DELETE FROM sessions WHERE session_id = ?`, sessionID)
	return err
}

// CrashAllTransitSessions marks all sessions stuck in generating/launching as crashed.
// Called at boot to clean up sessions whose goroutines died with the previous process.
func (db *DB) CrashAllTransitSessions() error {
	_, err := db.Exec(`
		UPDATE sessions SET status = 'crashed', status_deadline = NULL, generation_job_id = NULL, updated_at = ?
		WHERE status IN ('generating', 'launching')`,
		time.Now().UTC(),
	)
	return err
}

func scanSession(s scanner) (*Session, error) {
	var sess Session
	var bridgeContainerID, apContainerID, serverPassword, adminPassword, outputFile, generationJobID sql.NullString
	var bridgePort, apPort sql.NullInt64
	var statusDeadline sql.NullTime

	err := s.Scan(
		&sess.SessionID, &sess.Status,
		&bridgeContainerID, &apContainerID,
		&bridgePort, &apPort,
		&serverPassword, &adminPassword,
		&outputFile, &generationJobID,
		&statusDeadline,
		&sess.CreatedAt, &sess.UpdatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	if bridgeContainerID.Valid {
		sess.BridgeContainerID = &bridgeContainerID.String
	}
	if apContainerID.Valid {
		sess.APContainerID = &apContainerID.String
	}
	if bridgePort.Valid {
		p := int(bridgePort.Int64)
		sess.BridgePort = &p
	}
	if apPort.Valid {
		p := int(apPort.Int64)
		sess.APPort = &p
	}
	if serverPassword.Valid {
		sess.ServerPassword = &serverPassword.String
	}
	if adminPassword.Valid {
		sess.AdminPassword = &adminPassword.String
	}
	if outputFile.Valid {
		sess.OutputFile = &outputFile.String
	}
	if generationJobID.Valid {
		sess.GenerationJobID = &generationJobID.String
	}
	if statusDeadline.Valid {
		sess.StatusDeadline = &statusDeadline.Time
	}

	return &sess, nil
}
