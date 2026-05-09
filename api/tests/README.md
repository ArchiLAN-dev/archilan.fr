# Backend Tests

## Structure

- `Unit/` - pure domain and application logic with no I/O. No database, no HTTP, no Symfony container.
- `Functional/` - full HTTP request/response cycle through the Symfony kernel. Uses `WebTestCase` and the test database.
- `Integration/` - persistence and external adapter contracts. Uses the test database and real infrastructure (Doctrine repositories, Messenger transports, adapter stubs).
- `Fixtures/` - shared data factories and fixture classes used across test suites.

## Conventions

- Unit tests target `Domain/` and `Application/` classes.
- Functional tests target `Presentation/` (controllers, routes, response shapes).
- Integration tests target `Infrastructure/` (repositories, adapters).
- Each bounded context mirrors the production structure: `Unit/Identity/`, `Functional/Identity/`, etc.
