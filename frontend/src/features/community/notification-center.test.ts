import { hrefFor, messageFor } from "./notification-center";
import type { NotificationItem } from "./notifications-api";

function item(type: string, data: Record<string, unknown>): NotificationItem {
  return { id: "n-1", type, createdAt: "2026-09-24T10:00:00Z", read: false, actor: null, data };
}

describe("apworld incident notification (story 38.2)", () => {
  const opened = item("apworld_incident_opened", {
    incidentId: "incident-1",
    gameId: "game-1",
    gameName: "Crystal Project",
    incidentType: "preflight_failed",
  });

  it("names the game whose apworld failed", () => {
    expect(messageFor(opened)).toBe("Apworld en échec : Crystal Project");
  });

  it("leads to the apworld health page", () => {
    expect(hrefFor(opened)).toBe("/admin/sante-apworlds");
  });

  it("stays readable without a game name", () => {
    const bare = item("apworld_incident_opened", { gameId: "game-1" });

    expect(messageFor(bare)).toBe("Un apworld est en échec");
    expect(hrefFor(bare)).toBe("/admin/sante-apworlds");
  });

  it("names an update problem for what it is (story 38.6)", () => {
    expect(messageFor(item("apworld_incident_opened", { gameName: "Crystal Project", incidentType: "update_rejected" }))).toBe(
      "Mise à jour rejetée : Crystal Project",
    );
    expect(messageFor(item("apworld_incident_opened", { gameName: "Crystal Project", incidentType: "update_ambiguous" }))).toBe(
      "Mise à jour à arbitrer : Crystal Project",
    );
  });

  it("names a failure of a real generation for what it is (story 38.4)", () => {
    expect(messageFor(item("apworld_incident_opened", { gameName: "Crystal Project", incidentType: "default_yaml_failure" }))).toBe(
      "Échec avec le YAML par défaut : Crystal Project",
    );
  });

  it("names an image regression for what it is (story 38.9)", () => {
    expect(messageFor(item("apworld_incident_opened", { gameName: "Crystal Project", incidentType: "image_regression" }))).toBe(
      "Régression d'image : Crystal Project",
    );
  });

  it("leads to the health page even without any detail", () => {
    expect(hrefFor(item("apworld_incident_opened", {}))).toBe("/admin/sante-apworlds");
  });
});

describe("slot to review notification (story 38.7)", () => {
  const onRun = item("slot_yaml_needs_review", {
    runId: "run-1",
    runTitle: "Soirée Crystal",
    gameId: "game-1",
    gameName: "Crystal Project",
    slotId: "slot-1",
    reasons: ["« goal » : la valeur « moon » n'est plus acceptée."],
  });
  const onEvent = item("slot_yaml_needs_review", {
    eventId: "event-1",
    eventTitle: "LAN d'automne",
    registrationId: "reg-1",
    gameId: "game-1",
    gameName: "Crystal Project",
    slotId: "slot-1",
    reasons: ["« goal » : la valeur « moon » n'est plus acceptée."],
  });

  it("says which game changed and where", () => {
    expect(messageFor(onRun)).toBe("Crystal Project a changé de version : ton YAML est à revoir (« Soirée Crystal »)");
    expect(messageFor(onEvent)).toBe("Crystal Project a changé de version : ton YAML est à revoir (« LAN d'automne »)");
  });

  it("leads to the run game selection, or to the registration recap where the event slots are configured", () => {
    expect(hrefFor(onRun)).toBe("/runs/run-1/jeux");
    expect(hrefFor(onEvent)).toBe("/evenements/event-1/inscription/reg-1/recap");
  });

  it("stays readable without any detail", () => {
    const bare = item("slot_yaml_needs_review", {});

    expect(messageFor(bare)).toBe("Un jeu a changé de version : ton YAML est à revoir");
    expect(hrefFor(bare)).toBe("/compte");
  });
});

describe("moderation reply notification (story 39.3)", () => {
  it("tells the member the moderation answered, and leads to their account", () => {
    const reply = item("moderation_reply", {});

    expect(messageFor(reply)).toBe("La modération t'a répondu");
    expect(hrefFor(reply)).toBe("/compte");
  });
});
