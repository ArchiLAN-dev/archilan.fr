import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  adminNotesKey,
  fetchAdminNotes,
  fetchNoteRevisions,
  isAdminNote,
  turnAdminNote,
  writeAdminNote,
  type AdminNote,
} from "./admin-notes-api";
import { AdminNotesPanel, followUpLabel, NoteList } from "./admin-notes-panel";

const BASE = TEST_API_BASE_URL;

const jean = { id: "u1", displayName: "Jean" };
const lea = { id: "u2", displayName: "Léa" };

const open: AdminNote = {
  id: "n1",
  body: "A payé en espèces.",
  status: "open",
  author: jean,
  createdAt: "2026-10-30T10:00:00+01:00",
  updatedAt: "2026-10-30T10:00:00+01:00",
  edited: true,
  followUps: [{ id: "f1", kind: "comment", body: "À valider dans HelloAsso.", author: lea, createdAt: "2026-10-30T11:00:00+01:00" }],
};

const settled: AdminNote = {
  ...open,
  id: "n2",
  body: "Autorisation parentale reçue.",
  status: "settled",
  edited: false,
  followUps: [{ id: "f2", kind: "settled", body: "", author: lea, createdAt: "2026-10-30T12:00:00+01:00" }],
};

function render(node: React.ReactNode, notes?: AdminNote[] | null): string {
  const client = new QueryClient();
  if (notes !== undefined) client.setQueryData(adminNotesKey("registration", "r1"), notes);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

/** Story 46.2: the internal notes of a registration, in its admin page. */
describe("admin notes", () => {
  it("accepts a complete note and refuses an incomplete or unknown one", () => {
    expect(isAdminNote(open)).toBe(true);
    expect(isAdminNote({ ...open, status: "archived" })).toBe(false);
    expect(isAdminNote({ ...open, followUps: [{ id: "f", kind: "deleted", body: "", author: jean, createdAt: "x" }] })).toBe(false);
    expect(isAdminNote({ ...open, author: { id: "u1", displayName: null } })).toBe(true);
    const withoutTimeline: Partial<AdminNote> = { ...open };
    delete withoutTimeline.followUps;
    expect(isAdminNote(withoutTimeline)).toBe(false);
  });

  it("names who changed the state, and a deleted account", () => {
    expect(followUpLabel(open.followUps[0])).toBe("Léa");
    expect(followUpLabel({ ...open.followUps[0], kind: "settled" })).toBe("Léa a réglé la note");
    expect(followUpLabel({ ...open.followUps[0], kind: "reopened", author: { id: "u9", displayName: null } })).toBe("Compte supprimé a rouvert la note");
  });

  it("reads the notes of a target, keeps the valid ones, and folds a failure to null", async () => {
    let query = "";
    server.use(
      http.get(`${BASE}/admin/notes`, ({ request }) => {
        query = new URL(request.url).search;
        return HttpResponse.json({ data: [open, { id: "broken" }], meta: {} });
      }),
    );
    expect(await fetchAdminNotes("registration", "r1")).toEqual([open]);
    expect(query).toBe("?targetType=registration&targetId=r1");

    server.use(http.get(`${BASE}/admin/notes`, () => new HttpResponse(null, { status: 500 })));
    expect(await fetchAdminNotes("registration", "r1")).toBeNull();

    server.use(http.get(`${BASE}/admin/notes/n1/revisions`, () => HttpResponse.json({ data: [{ id: "v1", previousBody: "Avant.", editor: lea, editedAt: "2026-10-30T10:30:00+01:00" }], meta: {} })));
    expect(await fetchNoteRevisions("n1")).toEqual([{ id: "v1", previousBody: "Avant.", editor: lea, editedAt: "2026-10-30T10:30:00+01:00" }]);
  });

  it("writes a note, and reads the API's message on a refusal", async () => {
    let sent: unknown = null;
    server.use(
      http.post(`${BASE}/admin/notes`, async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ data: { id: "n3" }, meta: {} }, { status: 201 });
      }),
    );
    expect(await writeAdminNote("registration", "r1", "Arrive à 14 h.")).toBeNull();
    expect(sent).toEqual({ targetType: "registration", targetId: "r1", body: "Arrive à 14 h." });

    server.use(http.post(`${BASE}/admin/notes/n2/settle`, () => HttpResponse.json({ error: { code: "note_already_settled", message: "Cette note est déjà réglée." } }, { status: 409 })));
    expect(await turnAdminNote("n2", "settle", "")).toBe("Cette note est déjà réglée.");
  });

  it("says the notes are internal, and shows the empty state", () => {
    const html = render(<AdminNotesPanel targetId="r1" targetType="registration" />, []);
    expect(html).toContain("Notes internes");
    expect(html).toContain("Visibles des admins uniquement, jamais du membre.");
    expect(html).toContain("Aucune note.");
    expect(html).toContain("Nouvelle note (0/2000)");
  });

  it("shows an open note with its timeline, and folds a settled one", () => {
    const noop = async () => {};
    const html = render(<NoteList isPending={false} notes={[open, settled]} onChanged={noop} />);
    expect(html).toContain("A payé en espèces.");
    expect(html).toContain("(modifiée)");
    expect(html).toContain("À valider dans HelloAsso.");
    expect(html).toContain("Ouverte");
    expect(html).toContain("Historique");
    expect(html).toContain("Régler");
    // The settled note is one folded line: its timeline and actions stay hidden.
    expect(html).toContain("Autorisation parentale reçue.");
    expect(html).not.toContain("Léa a réglé la note");
    expect(html).not.toContain("Rouvrir");
  });

  it("says when the notes could not be read", () => {
    const noop = async () => {};
    expect(render(<NoteList isPending={false} notes={null} onChanged={noop} />)).toContain("Les notes n&#x27;ont pas pu être chargées.");
  });
});
