# Story 15.1: Infrastructure MinIO

Status: review

## Story

As an admin,
I want MinIO deployed in the local development environment,
So that object storage is available for APWorld files and media uploads.

## Acceptance Criteria

1. Given the monorepo `docker-compose.yml` exists, when the MinIO service is added and `docker-compose up` is run, then a MinIO instance is accessible at `http://localhost:9000` (API) and `http://localhost:9001` (console).
2. Two buckets are created on first startup: `apworlds` and `media`.
3. MinIO root credentials are configured via `MINIO_ROOT_USER` and `MINIO_ROOT_PASSWORD` env vars.
4. `api/.env` documents `MINIO_ENDPOINT`, `MINIO_ACCESS_KEY`, `MINIO_SECRET_KEY`, `MINIO_BUCKET_APWORLDS`, `MINIO_BUCKET_MEDIA`, and `MINIO_PRESIGN_TTL_SECONDS`.
5. A health check confirms MinIO is healthy before dependent services start.

## Tasks / Subtasks

- [x] Task 1: Add MinIO service and init container to docker-compose.yml (AC: 1, 2, 3, 5)
  - [x] Add `minio` service using `minio/minio:RELEASE.2025-04-22T22-12-26Z` with `server /data --console-address ":9001"` command
  - [x] Configure ports `9000:9000` and `9001:9001`
  - [x] Set `MINIO_ROOT_USER` and `MINIO_ROOT_PASSWORD` env vars with dev defaults
  - [x] Mount `minio-data:/data` volume
  - [x] Add healthcheck: `mc ready local` via curl on `http://localhost:9000/minio/health/live`
  - [x] Add `createbuckets` init service using `minio/mc` that waits for MinIO health and creates `apworlds` and `media` buckets
  - [x] Add `minio-data` volume declaration
- [x] Task 2: Add MinIO env vars to `api/.env` (AC: 4)
  - [x] Add `MINIO_ENDPOINT=http://localhost:9000`
  - [x] Add `MINIO_ACCESS_KEY=minioadmin`
  - [x] Add `MINIO_SECRET_KEY=minioadmin`
  - [x] Add `MINIO_BUCKET_APWORLDS=apworlds`
  - [x] Add `MINIO_BUCKET_MEDIA=media`
  - [x] Add `MINIO_PRESIGN_TTL_SECONDS=3600`
- [x] Task 3: Validate setup (AC: 1–5)
  - [x] Confirm docker-compose.yml is valid YAML
  - [x] Confirm env vars are documented

## Dev Notes

- MinIO is S3-compatible: the API will use `aws/aws-sdk-php` (or `league/flysystem-aws-s3-v3`) with the MinIO endpoint override in Story 15.2.
- The `createbuckets` init service pattern is the standard MinIO dev setup - it uses `minio/mc` (MinIO Client) to configure the server after it starts.
- Dev defaults: `MINIO_ROOT_USER=minioadmin` / `MINIO_ROOT_PASSWORD=minioadmin` - acceptable for local dev, must be changed in production.
- The bridge (Python) will not access MinIO directly; it uses pre-signed URLs provided by the API (Story 15.3). No MinIO env vars needed in `bridge/.env`.
- Bucket policies: both buckets remain private. Image URLs will be served via API proxy or pre-signed URLs (Stories 15.4–15.6).
- MinIO console on port 9001 is useful for dev inspection but should not be exposed in production.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-15.1]
- [Source: docker-compose.yml - existing service pattern (postgres, rabbitmq)]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Added `minio` service (`minio/minio:RELEASE.2025-04-22T22-12-26Z`) to docker-compose.yml: ports 9000 (API) and 9001 (console), `minio-data` volume, healthcheck via HTTP on `/minio/health/live`.
- Added `createbuckets` init service (`minio/mc`) that depends on MinIO health and creates the `apworlds` and `media` buckets idempotently (`--ignore-existing`).
- Added `minio-data` named volume.
- Added 6 MinIO env vars to `api/.env`: endpoint, credentials, bucket names, and presign TTL.
- docker-compose.yml validated with `docker compose config --quiet`: no errors.
- No Symfony bundle or APWorld upload changes in this story - that is Story 15.2.

### Debug Log

### File List

- `docker-compose.yml`
- `api/.env`
- `_bmad-output/implementation-artifacts/15-1-infrastructure-minio.md`

### Change Log

- 2026-05-12: Added MinIO and createbuckets services to docker-compose.yml; documented env vars in api/.env.
