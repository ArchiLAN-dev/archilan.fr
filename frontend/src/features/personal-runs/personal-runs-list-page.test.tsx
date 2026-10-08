import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { PersonalRunsListPage } from "./personal-runs-list-page";
import type { PersonalRun, PersonalRunStatus } from "./types";

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: () => undefined }),
}));

jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: { id: "u1" }, loading: false }),
}));

const run = (id: string, title: string, status: PersonalRunStatus, isOwner: boolean): PersonalRun => ({
  id,
  ownerId: isOwner ? "u1" : "u2",
  title,
  status,
  inviteToken: null,
  gameSelectionConfig: null,
  connectionHost: null,
  connectionPort: null,
  connectionPassword: null,
  isOwner,
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
  archived: false,
});

function render(owned: PersonalRun[], joined: PersonalRun[]): string {
  const client = new QueryClient();
  client.setQueryData(["personal-runs", "mine"], { owned, joined });

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <PersonalRunsListPage embedded />
    </QueryClientProvider>,
  );
}

/** Story 16.22: runs in progress come first, created or joined alike. */
describe("PersonalRunsListPage ordering", () => {
  test("a joined run in progress comes before an owned finished one", () => {
    const html = render([run("r1", "Ma partie finie", "completed", true)], [run("r2", "Partie d'un ami", "active", false)]);

    expect(html.indexOf("Partie d&#x27;un ami")).toBeGreaterThan(-1);
    expect(html.indexOf("Partie d&#x27;un ami")).toBeLessThan(html.indexOf("Ma partie finie"));
    expect(html).toContain("Rejointe");
  });

  test("a joined run sits in its status group, not in a section of its own", () => {
    const html = render([run("r1", "Ma partie", "active", true)], [run("r2", "Partie d'un ami", "active", false)]);

    expect(html).not.toContain("Parties rejointes");
    expect(html.match(/<h2[^>]*>En cours<\/h2>/g)).toHaveLength(1);
  });

  test("only the owner's paused run offers to resume", () => {
    const html = render([], [run("r2", "Partie d'un ami", "idle", false)]);

    expect(html).not.toContain("Reprendre");
  });
});
