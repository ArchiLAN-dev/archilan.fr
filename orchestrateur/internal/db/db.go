package db

import (
	"database/sql"
	"time"

	_ "modernc.org/sqlite"
)

type DB struct {
	*sql.DB
}

const schema = `
CREATE TABLE IF NOT EXISTS containers (
	session_id   TEXT PRIMARY KEY,
	port         INTEGER NOT NULL UNIQUE,
	status       TEXT NOT NULL DEFAULT 'starting',
	container_id TEXT,
	image        TEXT NOT NULL,
	created_at   DATETIME NOT NULL,
	updated_at   DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
	session_id          TEXT PRIMARY KEY,
	status              TEXT NOT NULL DEFAULT 'pending',
	bridge_container_id TEXT,
	ap_container_id     TEXT,
	bridge_port         INTEGER,
	ap_port             INTEGER,
	server_password     TEXT,
	admin_password      TEXT,
	output_file         TEXT,
	generation_job_id   TEXT,
	status_deadline     DATETIME,
	created_at          DATETIME NOT NULL,
	updated_at          DATETIME NOT NULL
);
`

func New(path string) (*DB, error) {
	sqldb, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, err
	}
	sqldb.SetMaxOpenConns(1)
	if _, err := sqldb.Exec(schema); err != nil {
		return nil, err
	}
	return &DB{sqldb}, nil
}

type Container struct {
	SessionID   string
	Port        int
	Status      string
	ContainerID *string
	Image       string
	CreatedAt   time.Time
	UpdatedAt   time.Time
}

func (db *DB) Insert(c *Container) error {
	_, err := db.Exec(
		`INSERT INTO containers (session_id, port, status, container_id, image, created_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?)`,
		c.SessionID, c.Port, c.Status, c.ContainerID, c.Image, c.CreatedAt, c.UpdatedAt,
	)
	return err
}

func (db *DB) UpdateStatus(sessionID, status string, containerID *string) error {
	_, err := db.Exec(
		`UPDATE containers SET status = ?, container_id = ?, updated_at = ? WHERE session_id = ?`,
		status, containerID, time.Now().UTC(), sessionID,
	)
	return err
}

func (db *DB) Delete(sessionID string) error {
	_, err := db.Exec(`DELETE FROM containers WHERE session_id = ?`, sessionID)
	return err
}

func (db *DB) Get(sessionID string) (*Container, error) {
	row := db.QueryRow(
		`SELECT session_id, port, status, container_id, image, created_at, updated_at
		 FROM containers WHERE session_id = ?`, sessionID,
	)
	return scanContainer(row)
}

func (db *DB) List() ([]*Container, error) {
	rows, err := db.Query(
		`SELECT session_id, port, status, container_id, image, created_at, updated_at
		 FROM containers ORDER BY created_at DESC`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []*Container
	for rows.Next() {
		c, err := scanContainer(rows)
		if err != nil {
			return nil, err
		}
		result = append(result, c)
	}
	return result, rows.Err()
}

func (db *DB) AllPorts() (map[int]string, error) {
	rows, err := db.Query(`SELECT port, session_id FROM containers`)
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

type scanner interface {
	Scan(dest ...any) error
}

func scanContainer(s scanner) (*Container, error) {
	var c Container
	err := s.Scan(&c.SessionID, &c.Port, &c.Status, &c.ContainerID, &c.Image, &c.CreatedAt, &c.UpdatedAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &c, err
}
