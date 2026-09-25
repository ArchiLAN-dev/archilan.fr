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

  it("leads to the health page even without any detail", () => {
    expect(hrefFor(item("apworld_incident_opened", {}))).toBe("/admin/sante-apworlds");
  });
});
