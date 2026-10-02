import { renderToStaticMarkup } from "react-dom/server";

import { adminActionLabel } from "@/features/admin/admin-user-activity";
import { CirculationView } from "./admin-pelles-dashboard";
import { adjustmentConfirmation, balanceFor } from "./admin-user-pelles";
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

  test("the confirmation shows the balance before and after", () => {
    expect(adjustmentConfirmation("credit", 50, 120)).toBe("Créditer 50 pelles ? Solde : 120 pelles → 170 pelles.");
    expect(adjustmentConfirmation("debit", 1, 1)).toBe("Débiter 1 pelle ? Solde : 1 pelle → 0 pelle.");
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

describe("CirculationView", () => {
  test("shows the totals, the weeks and the reasons", () => {
    const html = renderToStaticMarkup(
      <CirculationView
        circulation={{
          goldInCirculation: 60,
          created: 100,
          destroyed: 40,
          weeks: [{ weekStart: "2026-09-28", created: 100, destroyed: 40 }],
          byReason: [{ reason: "admin_credit", created: 100, destroyed: 0 }],
        }}
      />,
    );

    expect(html).toContain("En circulation");
    // The bars draw client-side; the same numbers sit in the screen-reader table.
    expect(html).toContain("<td>28 sept.</td><td>100</td><td>40</td>");
    expect(html).toContain("Crédit de l&#x27;équipe");
  });
});
