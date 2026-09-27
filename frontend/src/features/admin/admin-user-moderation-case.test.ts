import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchAdminUserModeration } from "./admin-users-api";

const BASE = TEST_API_BASE_URL;

function overview(moderationCase: unknown) {
  return {
    data: {
      state: { suspendedUntil: null, bannedAt: "2026-09-27T10:00:00+00:00", reason: "Triche" },
      unresolvedReportCount: 0,
      severityScore: 0,
      actions: [],
      case: moderationCase,
    },
  };
}

/**
 * Story 39.1 : le panneau de modération de la fiche admin montre le dossier du membre et le lien vers son
 * post dans le forum staff Discord.
 */
describe("fetchAdminUserModeration - dossier de modération", () => {
  it("lit le statut du dossier et le lien vers le post Discord", async () => {
    server.use(
      http.get(`${BASE}/admin/community/accounts/u1/moderation`, () =>
        HttpResponse.json(overview({ status: "open", forumThreadUrl: "https://discord.com/channels/g/t" })),
      ),
    );

    const moderation = await fetchAdminUserModeration("u1");

    expect(moderation?.moderationCase).toEqual({ status: "open", forumThreadUrl: "https://discord.com/channels/g/t", messages: [] });
  });

  it("lit les messages du dossier (story 39.2)", async () => {
    const message = {
      id: "m1",
      author: "member",
      authorName: "Lone",
      body: "J'aimerais être remboursé",
      source: "site",
      createdAt: "2026-09-27T10:00:00+00:00",
    };
    server.use(
      http.get(`${BASE}/admin/community/accounts/u1/moderation`, () =>
        HttpResponse.json(overview({ status: "open", forumThreadUrl: null, messages: [message, { id: 42 }] })),
      ),
    );

    const moderation = await fetchAdminUserModeration("u1");

    expect(moderation?.moderationCase?.messages).toEqual([message]);
  });

  it("un membre sans dossier n'en a pas", async () => {
    server.use(http.get(`${BASE}/admin/community/accounts/u1/moderation`, () => HttpResponse.json(overview(null))));

    const moderation = await fetchAdminUserModeration("u1");

    expect(moderation).not.toBeNull();
    expect(moderation?.moderationCase).toBeNull();
  });
});
