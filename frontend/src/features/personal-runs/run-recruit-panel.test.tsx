import { renderToStaticMarkup } from "react-dom/server";

import { recruitSummary, RunRecruitPanel, type RecruitState } from "./run-recruit-panel";

jest.mock("./run-invitations", () => ({
  InviteFriendsButton: () => <button type="button">Inviter des amis</button>,
}));

const friends: RecruitState = { openness: "friends", seatsWanted: null, pitch: null, plannedFor: null };

function panel(state: RecruitState): string {
  return renderToStaticMarkup(<RunRecruitPanel link={<p>LIEN</p>} openness={<p>REGLAGE</p>} participantIds={[]} runId="r1" state={state} />);
}

describe("RunRecruitPanel (stories 43.20, 43.22)", () => {
  it("is folded at first: the invitation and the current openness stay in sight, not the settings", () => {
    const html = panel(friends);

    expect(html).toContain("Faire venir des joueurs");
    expect(html).toContain("Ouverte à tes amis");
    expect(html).toContain("Inviter des amis");
    expect(html).toContain("Réglages et lien");
    expect(html).toContain('aria-expanded="false"');
    expect(html).not.toContain("REGLAGE");
    expect(html).not.toContain("LIEN");
  });

  it("shows a listing online as members read it, with the way to the board", () => {
    const html = panel({ openness: "members", seatsWanted: 2, pitch: "Venez nombreux", plannedFor: "2026-11-02T19:00:00Z" });

    expect(html).toContain("Annonce en ligne · 2 places");
    expect(html).toContain("Venez nombreux");
    expect(html).toContain("Prévue le");
    expect(html).toContain('href="/communaute/parties"');
    expect(panel(friends)).not.toContain("Voir dans les annonces");
  });

  it("sums up who can join", () => {
    expect(recruitSummary("invite", null)).toBe("Sur invitation");
    expect(recruitSummary("friends", null)).toBe("Ouverte à tes amis");
    expect(recruitSummary("friends", 1)).toBe("Ouverte à tes amis · 1 place");
    expect(recruitSummary("members", 3)).toBe("Annonce en ligne · 3 places");
  });
});
