import { renderToStaticMarkup } from "react-dom/server";

import { apiFetch } from "../../lib/apiFetch";

import { AdminRegistrationActions } from "./admin-registration-actions";
import { confirmAdminRegistration, createAdminRegistration, reactivateAdminRegistration, warningsOf } from "./admin-registration-api";
import { adminRegistrationPath } from "./admin-register-member";
import { AdminWarningsDialog } from "./admin-warnings";

jest.mock("../../lib/apiFetch");
jest.mock("next/navigation", () => ({ useRouter: () => ({ push: jest.fn() }) }));
const mockApiFetch = jest.mocked(apiFetch);

const WARNINGS = {
  error: {
    code: "warnings_to_acknowledge",
    message: "Cette action dépasse des règles d'inscription : confirme pour continuer.",
    details: {
      warnings: [
        { code: "registration_closed", message: "Les inscriptions sont closes." },
        { code: "capacity_full", message: "L'événement est complet : cette inscription dépasse la capacité." },
      ],
    },
  },
};

const noop = () => undefined;

describe("story 5.10 - an admin registers a member on their behalf", () => {
  beforeEach(() => {
    mockApiFetch.mockReset();
  });

  it("reads the warnings of a 409, and nothing else", () => {
    expect(warningsOf(WARNINGS)).toEqual([
      { code: "registration_closed", message: "Les inscriptions sont closes." },
      { code: "capacity_full", message: "L'événement est complet : cette inscription dépasse la capacité." },
    ]);
    expect(warningsOf({ error: { code: "already_active", message: "x", details: [] } })).toBeNull();
    expect(warningsOf(null)).toBeNull();
  });

  it("creates the registration, then opens it", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ data: { registrationId: "reg-1", outcome: "created" } }), { status: 201 }));

    await expect(createAdminRegistration("ev", "user-1")).resolves.toEqual({
      kind: "ok",
      data: { registrationId: "reg-1", outcome: "created" },
    });
    const [url, init] = mockApiFetch.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/admin\/events\/ev\/registrations$/);
    expect(init?.method).toBe("POST");
    expect(init?.body).toBe(JSON.stringify({ userId: "user-1", acknowledgeWarnings: false }));
    expect(adminRegistrationPath("ev", "reg-1")).toBe("/admin/evenements/ev/inscriptions/reg-1");
  });

  it("lists the rules to cross before registering anyway", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify(WARNINGS), { status: 409 }));

    const result = await createAdminRegistration("ev", "user-1");

    expect(result.kind).toBe("warnings");
  });

  it("reactivates and confirms through their own routes, the e-mail being a choice", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ outcome: "done" }), { status: 200 }));

    await reactivateAdminRegistration("ev", "reg", true);
    await confirmAdminRegistration("ev", "reg", false);

    const [reactivateUrl, reactivateInit] = mockApiFetch.mock.calls[0] ?? [];
    expect(String(reactivateUrl)).toContain("/admin/events/ev/registrations/reg/reactivate");
    expect(reactivateInit?.body).toBe(JSON.stringify({ acknowledgeWarnings: true }));
    const [confirmUrl, confirmInit] = mockApiFetch.mock.calls[1] ?? [];
    expect(String(confirmUrl)).toContain("/admin/events/ev/registrations/reg/confirm");
    expect(confirmInit?.body).toBe(JSON.stringify({ sendEmail: false, acknowledgeWarnings: false }));
  });

  it("offers to reactivate a cancelled registration, to confirm an unconfirmed one, and nothing once confirmed", () => {
    const cancelled = renderToStaticMarkup(
      <AdminRegistrationActions eventId="ev" onDone={noop} registrationId="reg" status="cancelled" submittedAt={null} />,
    );
    expect(cancelled).toContain("Réactiver l&#x27;inscription");
    expect(cancelled).not.toContain("Confirmer pour le joueur");

    const unconfirmed = renderToStaticMarkup(
      <AdminRegistrationActions eventId="ev" onDone={noop} registrationId="reg" status="reserved" submittedAt={null} />,
    );
    expect(unconfirmed).toContain("Confirmer pour le joueur");
    expect(unconfirmed).toContain("Envoyer le mail de confirmation au joueur");
    expect(unconfirmed).toMatch(/type="checkbox"[^>]*checked=""|checked=""[^>]*type="checkbox"/);

    const confirmed = renderToStaticMarkup(
      <AdminRegistrationActions eventId="ev" onDone={noop} registrationId="reg" status="reserved" submittedAt="2026-11-09T10:00:00+00:00" />,
    );
    expect(confirmed).toBe("");
  });

  it("shows every warning in the dialog, with the way through", () => {
    const html = renderToStaticMarkup(
      <AdminWarningsDialog
        busy={false}
        confirmLabel="Inscrire quand même"
        onCancel={noop}
        onConfirm={noop}
        warnings={[
          { code: "registration_closed", message: "Les inscriptions sont closes." },
          { code: "email_not_verified", message: "Le joueur n'a pas confirmé son adresse email." },
        ]}
      />,
    );

    expect(html).toContain("Les inscriptions sont closes.");
    expect(html).toContain("Le joueur n&#x27;a pas confirmé son adresse email.");
    expect(html).toContain("Inscrire quand même");
  });
});
