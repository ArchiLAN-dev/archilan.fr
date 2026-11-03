# Story 50.4 - Scans de sécurité en CI

Status: review

## Contexte

Suite de l'audit de sécurité du 2026-11-03 : garder le niveau sans refaire un audit à la main.

## Acceptance Criteria

1. Chaque PR passe gitleaks sur l'historique, `composer audit` et semgrep (sévérité ERROR bloquante).
2. Les faux positifs gitleaks sont listés dans `.gitleaks.toml`, chacun vérifié à la main.
3. Un scan ZAP baseline passif tourne chaque semaine sur le site, sans jamais bloquer, avec son rapport
   en artefact.

## Tasks

- [x] `.github/workflows/security.yml`
- [x] `.gitleaks.toml`

## Notes

- `pnpm audit` n'est pas branché : 1 alerte faible et 6 modérées au 2026-11-03, aucune haute.
- Semgrep remonte 42 avertissements non bloquants, à relire à la main.
