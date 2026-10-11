import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { hrefFor, messageFor } from "@/features/community/notification-center";
import { contentFor } from "@/features/community/notification-content";
import type { NotificationItem } from "@/features/community/notifications-api";
import { RunNudgeButton, RunNudgeMute } from "./run-nudges";
import type { RunNudgePlayer } from "./run-nudges-api";

jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: null, loading: false, setUser: () => undefined }),
}));

const idleBob: RunNudgePlayer = {
  userId: "bob",
  lastCheckAt: null,
  idle: true,
  muted: false,
  lastNudgedAt: null,
  nudgedHoursAgo: null,
  canNudge: true,
};

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

/** Story 43.12: nudging a co-player idle for two days. */
describe("run nudges", () => {
  test("offers « Relancer » for an idle co-player, never for oneself", () => {
    expect(render(<RunNudgeButton isMe={false} player={idleBob} runId="r1" />)).toContain("Relancer");
    expect(render(<RunNudgeButton isMe player={idleBob} runId="r1" />)).toBe("");
  });

  test("says when someone already nudged them today", () => {
    const nudged = { ...idleBob, canNudge: false, lastNudgedAt: "2026-10-10T08:00:00+00:00", nudgedHoursAgo: 3 };
    expect(render(<RunNudgeButton isMe={false} player={nudged} runId="r1" />)).toContain("Déjà relancé il y a 3 h");
  });

  test("shows nothing for a player who played lately or turned nudges off", () => {
    expect(render(<RunNudgeButton isMe={false} player={{ ...idleBob, idle: false, canNudge: false }} runId="r1" />)).toBe("");
    expect(render(<RunNudgeButton isMe={false} player={{ ...idleBob, muted: true, canNudge: false }} runId="r1" />)).toBe("");
    expect(render(<RunNudgeButton isMe={false} player={undefined} runId="r1" />)).toBe("");
  });

  test("lets the player turn nudges off for the run, then back on", () => {
    expect(render(<RunNudgeMute nudges={{ muted: false, players: [] }} runId="r1" />)).toContain("Ne plus me relancer pour cette partie");
    expect(render(<RunNudgeMute nudges={{ muted: true, players: [] }} runId="r1" />)).toContain("Accepter de nouveau les relances");
  });

  test("the bell says who waits and leads to the run", () => {
    const item: NotificationItem = {
      id: "n1",
      type: "run_nudge",
      createdAt: "2026-10-10T08:00:00+00:00",
      read: false,
      actor: { slug: "alice", displayName: "Alice", avatarUrl: null },
      data: { runId: "r1", runTitle: "Ma run" },
      details: null,
    };
    expect(messageFor(item)).toBe("Alice attend ta prochaine session dans « Ma run »");
    expect(hrefFor(item)).toBe("/runs/r1");
    expect(contentFor(item, "").label).toBe("Relance");
    expect(hrefFor({ ...item, data: {} })).toBe("/compte/parties");
  });
});
