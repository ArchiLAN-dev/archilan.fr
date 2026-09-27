import { renderToStaticMarkup } from "react-dom/server";

import { ModerationContactThread, sanctionLine } from "./moderation-contact-thread";

const noop = () => undefined;

/** Story 39.2 : ce que le membre sanctionné voit de son échange avec la modération. */
describe("ModerationContactThread", () => {
  test("décrit la sanction d'un membre bloqué", () => {
    expect(sanctionLine({ status: "banned", reason: "Récidive", suspendedUntil: null })).toBe(
      "Ton compte a été banni. Motif : Récidive",
    );
    expect(sanctionLine({ status: "suspended", reason: null, suspendedUntil: "2026-10-10T08:00:00+00:00" })).toBe(
      "Ton compte est suspendu jusqu'au 10/10/2026.",
    );
  });

  test("montre les messages envoyés et le formulaire", () => {
    const html = renderToStaticMarkup(
      <ModerationContactThread
        error={null}
        intro="Ton compte a été banni."
        messages={[
          { id: "m1", author: "member", body: "J'aimerais être remboursé", createdAt: "2026-09-27T10:00:00+00:00" },
          { id: "m2", author: "staff", body: "Le remboursement est en cours.", createdAt: "2026-09-27T11:00:00+00:00" },
        ]}
        onSend={noop}
        sending={false}
        sent={false}
      />,
    );

    expect(html).toContain("Contacter la modération");
    expect(html).toContain("Ton compte a été banni.");
    expect(html).toContain("J&#x27;aimerais être remboursé");
    expect(html).toContain("Réponse de la modération");
    expect(html).toContain("Le remboursement est en cours.");
    expect(html).toContain("maxLength=\"2000\"");
    expect(html).toContain("Envoyer");
  });

  test("confirme l'envoi et affiche une erreur", () => {
    const sent = renderToStaticMarkup(
      <ModerationContactThread error={null} intro={null} messages={[]} onSend={noop} sending={false} sent />,
    );
    expect(sent).toContain("Message envoyé à la modération.");

    const failed = renderToStaticMarkup(
      <ModerationContactThread error="Trop de messages" intro={null} messages={[]} onSend={noop} sending={false} sent={false} />,
    );
    expect(failed).toContain("Trop de messages");
  });
});
