package service

import (
	"context"
	"fmt"
	"time"

	"archilan.fr/orchestrateur/internal/webhook"
)

// RunSweeper performs boot recovery and then periodically checks for stuck/dead sessions.
func (s *Service) RunSweeper(ctx context.Context) {
	// Boot recovery: crash all sessions stuck in transit (goroutines died with old process)
	if err := s.db.CrashAllTransitSessions(); err != nil {
		s.log.Error("boot recovery failed", "err", err)
	}

	ticker := time.NewTicker(s.cfg.SweeperInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			s.sweep(ctx)
		}
	}
}

func (s *Service) sweep(ctx context.Context) {
	s.sweepTransit(ctx)
	s.sweepRunning(ctx)
}

func (s *Service) sweepTransit(ctx context.Context) {
	expired, err := s.db.ListExpiredSessionsInTransit(time.Now().UTC())
	if err != nil {
		s.log.Error("sweeper: list expired sessions failed", "err", err)
		return
	}

	for _, sess := range expired {
		switch sess.Status {
		case "generating":
			// Resolve container reference: prefer stored ID, fall back to well-known name.
			// GenerationJobID is only stored after GenerateMultiworld returns (container already
			// removed by then), so during a live generation the field is always empty — look up
			// by name instead so the sweeper can verify liveness correctly.
			containerRef := fmt.Sprintf("archilan-gen-%s", sess.SessionID)
			if sess.GenerationJobID != nil && *sess.GenerationJobID != "" {
				containerRef = *sess.GenerationJobID
			}
			info, _ := s.docker.Inspect(ctx, containerRef)
			if info != nil && info.Running {
				// Still running — extend deadline by 20% of the timeout
				newDeadline := time.Now().Add(s.cfg.GenerationTimeout / 5)
				_ = s.db.ExtendSessionDeadline(sess.SessionID, newDeadline)
				s.log.Info("sweeper: generation still running, extending deadline", "session_id", sess.SessionID)
				continue
			}
			// Container dead or unknown — crash
			s.log.Warn("sweeper: generation deadline exceeded, crashing session", "session_id", sess.SessionID)
			_ = s.db.UpdateSessionCrashed(sess.SessionID)
			s.webhook.Send(ctx, webhook.Payload{
				Event:     "session.crashed",
				SessionID: sess.SessionID,
				Error:     "generation deadline exceeded",
			})

		case "launching":
			// No container to inspect for launching (it's a goroutine)
			s.log.Warn("sweeper: launch deadline exceeded, crashing session", "session_id", sess.SessionID)
			if sess.BridgePort != nil {
				s.pool.Release(*sess.BridgePort)
			}
			_ = s.db.UpdateSessionCrashed(sess.SessionID)
			s.webhook.Send(ctx, webhook.Payload{
				Event:     "session.crashed",
				SessionID: sess.SessionID,
				Error:     "launch deadline exceeded",
			})
		}
	}
}

func (s *Service) sweepRunning(ctx context.Context) {
	sessions, err := s.db.ListRunningSessionsForReconciliation()
	if err != nil {
		s.log.Error("sweeper: list running sessions failed", "err", err)
		return
	}

	for _, sess := range sessions {
		if sess.APContainerID == nil {
			continue
		}
		info, err := s.docker.Inspect(ctx, *sess.APContainerID)
		if err != nil {
			continue // inspect error, skip
		}
		if info == nil || !info.Running {
			s.log.Warn("sweeper: AP server died, crashing session", "session_id", sess.SessionID)
			if sess.BridgePort != nil {
				s.pool.Release(*sess.BridgePort)
			}
			_ = s.db.UpdateSessionCrashed(sess.SessionID)
			s.webhook.Send(ctx, webhook.Payload{
				Event:     "session.crashed",
				SessionID: sess.SessionID,
				Error:     "AP server container died",
			})
		}
	}
}
