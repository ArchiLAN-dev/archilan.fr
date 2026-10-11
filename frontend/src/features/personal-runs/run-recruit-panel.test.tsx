import { renderToStaticMarkup } from "react-dom/server";

import { recruitSummary, RunRecruitPanel } from "./run-recruit-panel";

jest.mock("./run-invitations", () => ({
  InviteFriendsButton: () => <button type="button">Inviter des amis</button>,
}));

describe("RunRecruitPanel (story 43.20)", () => {
  it("is folded at first: the invitation and the current openness stay in sight, not the settings", () => {
    const html = renderToStaticMarkup(
      <RunRecruitPanel link={<p>LIEN</p>} openness={<p>REGLAGE</p>} participantIds={[]} runId="r1" summary="Ouverte à tes amis" />,
    );

    expect(html).toContain("Faire venir des joueurs");
    expect(html).toContain("Ouverte à tes amis");
    expect(html).toContain("Inviter des amis");
    expect(html).toContain('aria-expanded="false"');
    expect(html).not.toContain("REGLAGE");
    expect(html).not.toContain("LIEN");
  });

  it("sums up who can join", () => {
    expect(recruitSummary("invite", null)).toBe("Sur invitation");
    expect(recruitSummary("friends", null)).toBe("Ouverte à tes amis");
    expect(recruitSummary("friends", 1)).toBe("Ouverte à tes amis · 1 place");
    expect(recruitSummary("members", 3)).toBe("Annonce en ligne · 3 places");
  });
});
