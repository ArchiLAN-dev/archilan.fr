import { renderToStaticMarkup } from "react-dom/server";

import { adminActionLabel } from "@/features/admin/admin-user-activity";
import { AdjustmentSummary, adjustmentPreview, balanceFor } from "./admin-user-pelles";
import { pellesLabel } from "./pelle-amount";
import { WalletView } from "./wallet-panel";
import type { Wallet } from "./wallet-api";

const wallet: Wallet = {
  gold: 120,
  events: [{ eventId: "e1", eventTitle: "ArchiLAN #3", balance: 15 }],
  history: {
    items: [
      { id: "m1", amount: 50, kind: "gold", eventId: null, eventTitle: null, reason: "admin_credit", label: "Aide au montage", createdAt: "2026-10-03T12:00:00+00:00" },
      { id: "m2", amount: -20, kind: "gold", eventId: null, eventTitle: null, reason: "admin_debit", label: "Correction", createdAt: "2026-10-02T12:00:00+00:00" },
    ],
    page: 1,
    perPage: 25,
    total: 30,
  },
};

/** Story 41.1: the wallet, the admin confirmation and the circulation dashboard. */
describe("WalletView", () => {
  test("shows the gold balance, the event balances and the history", () => {
    const html = renderToStaticMarkup(<WalletView onPage={() => {}} wallet={wallet} />);

    expect(html).toContain("120");
    expect(html).toContain("ArchiLAN #3");
    expect(html).toContain("Aide au montage");
    expect(html).toContain("Crédit de l&#x27;équipe");
    expect(html).toContain("+50");
    expect(html).toContain("-20");
    expect(html).toContain("Page 1 sur 2");
  });

  test("an empty wallet says so", () => {
    const html = renderToStaticMarkup(
      <WalletView onPage={() => {}} wallet={{ gold: 0, events: [], history: { items: [], page: 1, perPage: 25, total: 0 } }} />,
    );

    expect(html).toContain("Aucun mouvement pour le moment.");
    expect(html).not.toContain("Page 1 sur");
  });
});

describe("admin adjustment", () => {
  test("the balance the movement applies to", () => {
    expect(balanceFor(wallet, "gold", null)).toBe(120);
    expect(balanceFor(wallet, "event", "e1")).toBe(15);
    expect(balanceFor(wallet, "event", "other")).toBe(0);
  });

  test("the confirmation computes the movement and the balance after", () => {
    expect(adjustmentPreview("credit", 50, 120, "Pelles d'or", "Lot du quiz")).toEqual({
      direction: "credit",
      movement: 50,
      before: 120,
      after: 170,
      kindLabel: "Pelles d'or",
      reason: "Lot du quiz",
    });
    expect(adjustmentPreview("debit", 1, 1, "Pelles d'or", "Erreur")).toMatchObject({ movement: -1, after: 0 });
  });

  test("the confirmation summary shows the movement, both balances and the reason (story 39.15)", () => {
    const html = renderToStaticMarkup(<AdjustmentSummary preview={adjustmentPreview("credit", 1500, 0, "Pelles d'or", "Lot du quiz")} />);

    expect(html).toContain(`+${new Intl.NumberFormat("fr-FR").format(1500)}`);
    expect(html).toContain("text-success");
    expect(html).toContain("Solde actuel");
    expect(html).toContain("Nouveau solde");
    expect(html).toContain("Pelles d&#x27;or");
    expect(html).toContain("Lot du quiz");

    const debit = renderToStaticMarkup(<AdjustmentSummary preview={adjustmentPreview("debit", 20, 50, "Pelles d'or", "Erreur")} />);
    expect(debit).toContain("-20");
    expect(debit).toContain("text-danger");
  });

  test("pelles count wording", () => {
    expect(pellesLabel(1200)).toBe(`1${" "}200 pelles`);
  });

  test("the journal names a pelles adjustment", () => {
    expect(adminActionLabel("pelles_credit")).toBe("Pelles créditées");
    expect(adminActionLabel("pelles_debit")).toBe("Pelles débitées");
    expect(adminActionLabel(null)).toBe("Action inconnue");
  });
});
