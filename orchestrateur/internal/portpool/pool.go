package portpool

import (
	"errors"
	"sync"
)

var ErrExhausted = errors.New("port pool exhausted")

type Pool struct {
	mu    sync.Mutex
	start int
	end   int
	used  map[int]string // port → sessionID
}

func New(start, end int) *Pool {
	return &Pool{
		start: start,
		end:   end,
		used:  make(map[int]string),
	}
}

// Reserve marks a port as in use without acquiring it from the pool.
// Used when recovering existing sessions from the DB at startup.
func (p *Pool) Reserve(port int, sessionID string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.used[port] = sessionID
}

// Acquire returns a free port and marks it as used.
func (p *Pool) Acquire(sessionID string) (int, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	for port := p.start; port <= p.end; port++ {
		if _, ok := p.used[port]; !ok {
			p.used[port] = sessionID
			return port, nil
		}
	}
	return 0, ErrExhausted
}

// Release frees a port back to the pool.
func (p *Pool) Release(port int) {
	p.mu.Lock()
	defer p.mu.Unlock()
	delete(p.used, port)
}
