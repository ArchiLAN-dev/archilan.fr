.PHONY: e2e-weekly

# Live end-to-end smoke test for the Weekly Run flow (story 23.13).
# Requires the dev stack up (docker compose up) and the archipelago image built.
# See scripts/e2e/README.md for prerequisites and configuration.
e2e-weekly:
	bash scripts/e2e/weekly-smoke.sh
