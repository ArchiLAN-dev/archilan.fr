import { renderToStaticMarkup } from "react-dom/server";

import { hrefFor, messageFor } from "@/features/community/notification-center";
import { contentFor } from "@/features/community/notification-content";
import type { NotificationItem } from "@/features/community/notifications-api";
import { ReadinessSummary, ReadyBadge, ReadyToggle, readinessLabel } from "./run-readiness";
import type { PersonalRunParticipant } from "./types";

const bob: PersonalRunParticipant = {
  userId: "bob",
  slug: "bob",
  displayName: "Bob",
  avatarUrl: null,
  joinedAt: "2026-10-17T10:00:00+00:00",
  slotCount: 1,
  isMember: false,
  isAdmin: false,
  level: 3,
  playing: false,
  ready: false,
  canBeReady: true,
};

/** Story 16.23: the ready status of a private run's players. */
describe("run readiness", () => {
  test("a ready player has a badge, the others none at all", () => {
    expect(renderToStaticMarkup(<ReadyBadge participant={{ ...bob, ready: true }} />)).toContain("Prêt");
    expect(renderToStaticMarkup(<ReadyBadge participant={bob} />)).toBe("");
    expect(renderToStaticMarkup(<ReadyBadge participant={{ ...bob, ready: undefined }} />)).toBe("");
  });

  test("the count reads « 3 / 4 prêts », then everyone", () => {
    expect(readinessLabel({ ready: 3, total: 4 })).toBe("3 / 4 prêts");
    expect(readinessLabel({ ready: 1, total: 4 })).toBe("1 / 4 prêt");
    expect(readinessLabel({ ready: 0, total: 2 })).toBe("0 / 2 prêt");
    expect(readinessLabel({ ready: 4, total: 4 })).toBe("Tout le monde est prêt");
    expect(readinessLabel({ ready: 0, total: 0 })).toBeNull();
    expect(readinessLabel(null)).toBeNull();
    expect(renderToStaticMarkup(<ReadinessSummary readiness={null} />)).toBe("");
  });

  test("only a player who has something to play gets the switch", () => {
    expect(renderToStaticMarkup(<ReadyToggle onChanged={() => undefined} participant={bob} runId="r1" />)).toContain("Je suis prêt");
    expect(renderToStaticMarkup(<ReadyToggle onChanged={() => undefined} participant={{ ...bob, ready: true }} runId="r1" />)).toContain("Prêt");
    expect(renderToStaticMarkup(<ReadyToggle onChanged={() => undefined} participant={{ ...bob, canBeReady: false }} runId="r1" />)).toBe("");
  });

  test("the owner's notification leads to the run", () => {
    const notice: NotificationItem = {
      id: "n1",
      type: "run_all_ready",
      createdAt: new Date(2026, 9, 17, 18, 0).toISOString(),
      read: false,
      actor: null,
      data: { runId: "r1", runTitle: "Ma run" },
      details: null,
    };

    expect(messageFor(notice)).toBe("Tout le monde est prêt dans « Ma run » : tu peux lancer");
    expect(contentFor(notice, "").title).toEqual([{ text: "Tout le monde est prêt dans " }, { text: "Ma run", strong: true }, { text: " : tu peux lancer" }]);
    expect(hrefFor(notice)).toBe("/runs/r1");
  });
});
