import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { hrefFor, messageFor } from "@/features/community/notification-center";
import { contentFor } from "@/features/community/notification-content";
import type { NotificationItem } from "@/features/community/notifications-api";
import { NonRespondentList, RemindButton } from "./admin-survey-results";
import { remindSurvey } from "./admin-surveys-api";

function item(type: string, data: Record<string, unknown>): NotificationItem {
  return { id: "n1", type, createdAt: new Date(2026, 10, 16, 17, 40).toISOString(), read: false, actor: null, data, details: null };
}

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

const data = { surveyId: "s1", surveyTitle: "Quand viens-tu ?", eventId: "e1", eventTitle: "ArchiLAN 4", anonymous: false };

/** Story 51.3: the audience of a survey hears of it, and an admin reminds those who have not answered. */
describe("survey notices", () => {
  it("tells a survey opened and leads to its answer page", () => {
    const opened = item("survey_opened", data);

    expect(messageFor(opened)).toBe("Nouveau sondage : « Quand viens-tu ? »");
    expect(hrefFor(opened)).toBe("/evenements/e1/sondages/s1");
    expect(contentFor(opened, "")).toMatchObject({ label: "Sondage", detail: "ArchiLAN 4" });
  });

  it("reminds a member who has not answered yet", () => {
    const reminder = item("survey_reminder", data);

    expect(messageFor(reminder)).toBe("Tu n'as pas encore répondu à « Quand viens-tu ? »");
    expect(contentFor(reminder, "").title).toEqual([{ text: "Tu n'as pas encore répondu à " }, { text: "Quand viens-tu ?", strong: true }]);
    expect(hrefFor(item("survey_reminder", {}))).toBe("/compte");
  });

  it("shows the remind button with the last reminder", () => {
    const html = render(
      <NonRespondentList eventId="e1" members={[{ userId: "u1", registrationId: "r1", displayName: "Dave" }]}>
        <RemindButton lastRemindedAt="2026-11-17T11:00:00+00:00" surveyId="s1" />
      </NonRespondentList>,
    );
    expect(html).toContain("Relancer les non-répondants");
    expect(html).toContain("Dernière relance le 17/11/2026 12:00");
    expect(render(<RemindButton lastRemindedAt={null} surveyId="s1" />)).toContain("48 h avant la fermeture");
  });

  it("reminds through the API and gives its refusal", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/surveys/s1/remind`, () => HttpResponse.json({ data: { recipients: 3, lastRemindedAt: "2026-11-17T11:00:00+00:00" }, meta: {} })),
      http.post(`${TEST_API_BASE_URL}/admin/surveys/s2/remind`, () =>
        HttpResponse.json(
          { error: { code: "survey_remind_too_soon", message: "Une relance est déjà partie dans les dernières 24 h.", details: [] }, data: { lastRemindedAt: null } },
          { status: 429 },
        ),
      ),
    );

    await expect(remindSurvey("s1")).resolves.toEqual({ ok: true, recipients: 3 });
    await expect(remindSurvey("s2")).resolves.toEqual({ ok: false, message: "Une relance est déjà partie dans les dernières 24 h." });
  });
});
