import { renderToStaticMarkup } from "react-dom/server";

import { CoOwnerBadge, CoOwnerToggle } from "./run-co-owner";
import type { PersonalRunParticipant } from "./types";

const bob: PersonalRunParticipant = {
  userId: "bob",
  slug: "bob",
  displayName: "Bob",
  avatarUrl: null,
  joinedAt: "2026-11-22T10:00:00+00:00",
  slotCount: 1,
  isMember: false,
  isAdmin: false,
  level: 3,
  playing: false,
};

/** Story 16.27: a private run's co-organisers. */
describe("run co-owner", () => {
  test("only a co-organiser has the badge", () => {
    expect(renderToStaticMarkup(<CoOwnerBadge participant={{ ...bob, coOwner: true }} />)).toContain("Co-organisateur");
    expect(renderToStaticMarkup(<CoOwnerBadge participant={bob} />)).toBe("");
  });

  test("the owner's switch names, then takes back", () => {
    const naming = renderToStaticMarkup(<CoOwnerToggle onChanged={() => {}} participant={bob} runId="run-1" />);
    expect(naming).toContain("Co-organisateur");
    expect(naming).toContain('aria-pressed="false"');

    const revoking = renderToStaticMarkup(<CoOwnerToggle onChanged={() => {}} participant={{ ...bob, coOwner: true }} runId="run-1" />);
    expect(revoking).toContain("Retirer les droits");
    expect(revoking).toContain('aria-pressed="true"');
  });
});
