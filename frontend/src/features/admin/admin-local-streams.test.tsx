import { renderToStaticMarkup } from "react-dom/server";

import { RegistrationList } from "./admin-registration-dashboard";
import type { AdminRegistration } from "./admin-registration-api";
import { isAdminLocalStreams, localStreamLabel, mediaServerHubState, type AdminLocalStreams } from "./admin-events-api";

function view(overrides: Partial<AdminLocalStreams> = {}): AdminLocalStreams {
  return { mediaServerHost: "media.lan.archilan.fr", agentSeenSecondsAgo: 4, liveCount: 2, streams: [], ...overrides };
}

function registration(id: string, displayName: string): AdminRegistration {
  return {
    registrationId: id,
    status: "reserved",
    usedPrivateAccess: false,
    broadcastConsent: true,
    createdAt: "2026-10-01T10:00:00+02:00",
    submittedAt: null,
    participant: { userId: `u-${id}`, displayName, email: `${id}@example.org` },
    selectedGames: [],
    gameSelectionComplete: false,
    payment: null,
  };
}

const READY = { kind: "ready" as const, registrations: [registration("r1", "Alice"), registration("r2", "Bob"), registration("r3", "Chloé")], total: 3 };

/** Story 47.9: the admin follows the régie agent and who is on air from the LAN. */
describe("admin local streams", () => {
  it("reads the API view and refuses a malformed one", () => {
    expect(isAdminLocalStreams(view({ streams: [{ registrationId: "r1", live: false, cutMinutesAgo: 3 }] }))).toBe(true);
    expect(isAdminLocalStreams(view({ mediaServerHost: null, agentSeenSecondsAgo: null }))).toBe(true);
    expect(isAdminLocalStreams({ ...view(), liveCount: "2" })).toBe(false);
    expect(isAdminLocalStreams({ ...view(), streams: [{ registrationId: "r1", live: "yes", cutMinutesAgo: null }] })).toBe(false);
  });

  it("states the media server line of the hub", () => {
    expect(mediaServerHubState(null)).toBeUndefined();
    expect(mediaServerHubState(view({ mediaServerHost: null }))).toBe("Non configuré");
    expect(mediaServerHubState(view({ agentSeenSecondsAgo: null }))).toBe("Agent jamais vu");
    expect(mediaServerHubState(view())).toBe("Agent vu il y a 4 s - 2 en direct");
    expect(mediaServerHubState(view({ agentSeenSecondsAgo: 30 }))).toBe("Agent vu il y a 30 s - 2 en direct");
    expect(mediaServerHubState(view({ agentSeenSecondsAgo: 31 }))).toBe("Agent muet depuis 1 min");
    expect(mediaServerHubState(view({ agentSeenSecondsAgo: 185 }))).toBe("Agent muet depuis 3 min");
  });

  it("labels a registrant's local stream", () => {
    expect(localStreamLabel(undefined)).toBe("Jamais");
    expect(localStreamLabel({ registrationId: "r1", live: true, cutMinutesAgo: null })).toBe("En direct");
    expect(localStreamLabel({ registrationId: "r1", live: false, cutMinutesAgo: 0 })).toBe("Coupé à l'instant");
    expect(localStreamLabel({ registrationId: "r1", live: false, cutMinutesAgo: 12 })).toBe("Coupé il y a 12 min");
  });

  it("shows the column only for an event with a media server", () => {
    const without = renderToStaticMarkup(<RegistrationList eventId="e1" highlightedRegistrationIds={new Set()} openNotes={{}} state={READY} />);
    expect(without).not.toContain("Diffusion locale");

    const html = renderToStaticMarkup(
      <RegistrationList
        eventId="e1"
        highlightedRegistrationIds={new Set()}
        localStreams={{
          r1: { registrationId: "r1", live: true, cutMinutesAgo: null },
          r2: { registrationId: "r2", live: false, cutMinutesAgo: 7 },
        }}
        openNotes={{}}
        state={READY}
      />,
    );
    expect(html).toContain("Diffusion locale");
    expect(html).toContain("En direct");
    expect(html).toContain("Coupé il y a 7 min");
    expect(html).toContain("Jamais");
  });
});
