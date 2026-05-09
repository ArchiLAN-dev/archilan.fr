# Backend Source Boundaries

Symfony owns business rules, persistence, authentication, authorization, and external integrations.

Each bounded context uses four layers:

- `Domain`: entities, value objects, domain services, repository interfaces.
- `Application`: commands, queries, handlers, use cases, DTOs.
- `Infrastructure`: Doctrine repositories, external adapters, Messenger handlers.
- `Presentation`: controllers and request/response mapping.

Controllers translate HTTP to application commands or queries. They must not contain business rules.

The generated `Controller`, `Entity`, and `Repository` directories are framework placeholders from the starter. Replace them with bounded-context mappings when DDD implementation begins.
