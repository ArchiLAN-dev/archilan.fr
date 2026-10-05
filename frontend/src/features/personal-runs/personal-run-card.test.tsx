import { renderToStaticMarkup } from "react-dom/server";

import { PersonalRunCard } from "./personal-run-card";
import type { PersonalRun, PersonalRunStatus } from "./types";

const run = (status: PersonalRunStatus, archived = false): PersonalRun => ({
  id: "r1",
  ownerId: "u1",
  title: "Ma partie",
  status,
  inviteToken: null,
  gameSelectionConfig: null,
  connectionHost: null,
  connectionPort: null,
  connectionPassword: null,
  isOwner: true,
  canStart: false,
  participants: [],
  sessionId: null,
  recapPublic: false,
  lastActivityAt: null,
  pausedWithoutSave: false,
  validationErrors: null,
  adminPassword: null,
  createdAt: "2026-10-05T10:00:00Z",
  updatedAt: "2026-10-05T10:00:00Z",
  archived,
});

/** Story 16.21: a member puts a run away in their own list, from its card. */
describe("PersonalRunCard archive action", () => {
  test("a run without a live party can be archived", () => {
    for (const status of ["draft", "completed", "cancelled"] as const) {
      expect(renderToStaticMarkup(<PersonalRunCard onArchive={() => {}} run={run(status)} />)).toContain(">Archiver<");
    }
  });

  test("a live run cannot - it must be stopped first", () => {
    for (const status of ["starting", "active", "stopping", "idle", "restarting"] as const) {
      expect(renderToStaticMarkup(<PersonalRunCard onArchive={() => {}} run={run(status)} />)).not.toContain("Archiver");
    }
  });

  test("an archived run comes back", () => {
    expect(renderToStaticMarkup(<PersonalRunCard onArchive={() => {}} run={run("completed", true)} />)).toContain(">Désarchiver<");
  });

  test("no action without a handler", () => {
    expect(renderToStaticMarkup(<PersonalRunCard run={run("draft")} />)).not.toContain("Archiver");
  });
});
