import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchAdminUserModeration, hasPendingDiscordOutcome, replyToMember, type AdminUserModeration } from "./admin-users-api";

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
      discordDm: null,
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

  it("répond au membre et remonte le refus de l'API (story 39.3)", async () => {
    let received: unknown = null;
    server.use(
      http.post(`${BASE}/admin/community/accounts/u1/moderation/replies`, async ({ request }) => {
        received = await request.json();
        return HttpResponse.json({ data: { sent: true } }, { status: 201 });
      }),
      http.post(`${BASE}/admin/community/accounts/u2/moderation/replies`, () =>
        HttpResponse.json(
          { error: { code: "not_sanctioned", message: "Aucune sanction sur ce compte : pas de dossier où répondre.", details: {} } },
          { status: 403 },
        ),
      ),
    );

    await expect(replyToMember("u1", "C'est en cours")).resolves.toBeNull();
    expect(received).toEqual({ body: "C'est en cours" });
    await expect(replyToMember("u2", "Bonjour")).resolves.toBe("Aucune sanction sur ce compte : pas de dossier où répondre.");
  });

  it("lit l'issue Discord de chaque sanction (stories 39.4 et 39.5)", async () => {
    const action = {
      id: "a1",
      action: "ban",
      reason: "Triche",
      createdAt: "2026-09-27T10:00:00+00:00",
      actorId: "admin-1",
      actorName: "Jean",
      relatedReportId: null,
    };
    server.use(
      http.get(`${BASE}/admin/community/accounts/u1/moderation`, () =>
        HttpResponse.json({
          data: {
            state: { suspendedUntil: null, bannedAt: "2026-09-27T10:00:00+00:00", reason: "Triche" },
            unresolvedReportCount: 0,
            severityScore: 0,
            actions: [{ ...action, discordDm: "sent", discordServer: "banned" }, { ...action, id: "a0" }],
            case: null,
          },
        }),
      ),
    );

    const moderation = await fetchAdminUserModeration("u1");

    expect(moderation?.actions.map((a) => [a.discordDm, a.discordServer])).toEqual([
      ["sent", "banned"],
      [null, null],
    ]);
  });

  it("attend l'issue Discord d'une sanction ou d'une réponse récente (story 39.9)", () => {
    const now = Date.parse("2026-09-28T10:00:00+00:00");
    const base: AdminUserModeration = {
      state: { suspendedUntil: null, bannedAt: null, reason: null },
      unresolvedReportCount: 0,
      severityScore: 0,
      actions: [],
      moderationCase: null,
    };
    const action = {
      id: "a1",
      action: "ban",
      reason: "Triche",
      actorId: "admin-1",
      actorName: "Jean",
      relatedReportId: null,
      discordServer: null,
    };

    expect(hasPendingDiscordOutcome(base, now)).toBe(false);
    expect(hasPendingDiscordOutcome({ ...base, actions: [{ ...action, createdAt: "2026-09-28T09:58:00+00:00", discordDm: null }] }, now)).toBe(true);
    expect(
      hasPendingDiscordOutcome({ ...base, actions: [{ ...action, createdAt: "2026-09-28T09:58:00+00:00", discordDm: "sent" }] }, now),
    ).toBe(false);
    // A sanction older than the epic has no outcome and never will: no endless refresh.
    expect(hasPendingDiscordOutcome({ ...base, actions: [{ ...action, createdAt: "2026-06-01T10:00:00+00:00", discordDm: null }] }, now)).toBe(false);
    expect(
      hasPendingDiscordOutcome(
        {
          ...base,
          moderationCase: {
            status: "open",
            forumThreadUrl: null,
            messages: [
              { id: "m1", author: "staff", authorName: "Jean", body: "Ok", source: "site", createdAt: "2026-09-28T09:59:00+00:00", discordDm: null },
            ],
          },
        },
        now,
      ),
    ).toBe(true);
  });
});
