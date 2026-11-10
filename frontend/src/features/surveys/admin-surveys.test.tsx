import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { SurveyList } from "./admin-event-surveys";
import { AdminSurveyEditor, optionLines } from "./admin-survey-editor";
import { answerGroups, answerText, filterRespondents, NonRespondentList, RespondentList, ResultsViews, SurveyResultsView } from "./admin-survey-results";
import {
  addQuestion,
  fetchRegistrationSurveys,
  fetchSurveyResults,
  fromLocalInput,
  isAdminSurvey,
  isSurveyResults,
  percent,
  type AdminSurvey,
  type RespondentAnswers,
  type SurveyResults,
} from "./admin-surveys-api";
import { labelledAnswer, RegistrationSurveys } from "./registration-surveys-panel";

jest.mock("next/navigation", () => ({ useRouter: () => ({ push: jest.fn(), replace: jest.fn() }) }));

const BASE = TEST_API_BASE_URL;

const survey: AdminSurvey = {
  id: "s1",
  eventId: "e1",
  title: "Quand viens-tu ?",
  intro: "",
  audience: "registrants",
  anonymous: false,
  sendEmail: true,
  status: "published",
  opensAt: "2026-11-10T08:00:00+00:00",
  closesAt: "2026-11-20T17:00:00+00:00",
  publishedAt: "2026-11-10T08:00:00+00:00",
  openingNotifiedAt: "2026-11-10T08:10:00+00:00",
  lastRemindedAt: null,
  questions: [
    {
      id: "coming",
      kind: "single",
      label: "Tu viens ?",
      required: true,
      position: 0,
      options: [
        { id: "yes", label: "Oui", position: 0, retired: false },
        { id: "no", label: "Non", position: 1, retired: false },
        { id: "maybe", label: "Peut-être", position: 2, retired: true },
      ],
    },
    { id: "mark", kind: "rating", label: "Ta note", required: false, position: 1, options: [] },
    { id: "word", kind: "text", label: "Un mot ?", required: false, position: 2, options: [] },
  ],
};

const results: SurveyResults = {
  surveyId: "s1",
  eventId: "e1",
  title: "Quand viens-tu ?",
  anonymous: false,
  status: "published",
  closesAt: "2026-11-20T17:00:00+00:00",
  respondentCount: 3,
  audienceSize: 4,
  textsHidden: false,
  questions: [
    {
      id: "coming",
      kind: "single",
      label: "Tu viens ?",
      required: true,
      answerCount: 3,
      options: [
        { id: "yes", label: "Oui", retired: false, count: 2 },
        { id: "no", label: "Non", retired: false, count: 1 },
        { id: "maybe", label: "Peut-être", retired: true, count: 0 },
      ],
      rating: null,
      texts: null,
    },
    { id: "mark", kind: "rating", label: "Ta note", required: false, answerCount: 2, options: [], rating: { average: 4.5, counts: [0, 0, 0, 1, 1] }, texts: null },
    { id: "word", kind: "text", label: "Un mot ?", required: false, answerCount: 2, options: [], rating: null, texts: ["Alors on danse", "zut alors"] },
  ],
};

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

/** Story 51.4: the admins write a survey and read its results. */
describe("admin surveys", () => {
  it("validates the API payloads", () => {
    expect(isAdminSurvey(survey)).toBe(true);
    expect(isAdminSurvey({ ...survey, audience: "everyone" })).toBe(false);
    expect(isSurveyResults(results)).toBe(true);
    expect(isSurveyResults({ ...results, questions: [{ ...results.questions[1], rating: { average: "4", counts: [] } }] })).toBe(false);
  });

  it("shows choices with their share, the average mark and the texts", () => {
    const html = render(<SurveyResultsView results={results} />);
    expect(html).toContain("<span class=\"font-semibold text-foreground\">3</span> réponses sur");
    expect(html).toContain("(75 %)");
    expect(html).toContain("2 · 67 %");
    expect(html).toContain("Peut-être (retirée)");
    expect(html).toContain("4,5 / 5 de moyenne");
    expect(html.indexOf("Alors on danse")).toBeLessThan(html.indexOf("zut alors"));
  });

  it("hides the texts of an anonymous survey below three responses", () => {
    const anonymous: SurveyResults = {
      ...results,
      anonymous: true,
      respondentCount: 2,
      textsHidden: true,
      questions: results.questions.map((question) => ({ ...question, texts: null })),
    };
    const html = render(<SurveyResultsView results={anonymous} />);
    expect(html).toContain("visibles à partir de 3 réponses");
    expect(html).toContain("Sondage anonyme");
    expect(html).not.toContain("zut alors");
  });

  it("says when nobody has answered yet", () => {
    const empty: SurveyResults = { ...results, respondentCount: 0, questions: [] };
    expect(render(<SurveyResultsView results={empty} />)).toContain("Personne n&#x27;a encore répondu.");
  });

  it("lists the non-respondents and the answers of each member, linked to their registration", () => {
    const missing = render(<NonRespondentList eventId="e1" members={[{ userId: "u4", registrationId: "r4", displayName: "Dave" }]} />);
    expect(missing).toContain("Pas encore répondu (1)");
    expect(missing).toContain('href="/admin/evenements/e1/inscriptions/r4"');

    const answers = render(
      <RespondentList
        eventId="e1"
        questions={survey.questions}
        respondents={[
          {
            userId: "u2",
            displayName: "Bob",
            registrationId: "r2",
            respondedAt: "2026-11-11T10:00:00+00:00",
            answers: [
              { questionId: "coming", optionIds: ["no"], text: null, rating: null },
              { questionId: "mark", optionIds: [], text: null, rating: 4 },
            ],
          },
        ]}
      />,
    );
    expect(answers).toContain('href="/admin/evenements/e1/inscriptions/r2"');
    expect(answers).toContain("Non");
    expect(answers).toContain("4 / 5");
    expect(answerText(survey.questions[2], undefined)).toBe("-");
    expect(answerText(survey.questions[0], { questionId: "coming", optionIds: ["gone"], text: null, rating: null })).toBe("Option supprimée");
  });

  it("locks the audience, the anonymity and the kinds of a published survey", () => {
    const published = render(<AdminSurveyEditor survey={survey} />);
    expect(published).not.toContain("Publier");
    expect(published).not.toContain("Supprimer la question");
    expect(published).toContain("Retirer");
    expect(published).toContain("Peut-être (retirée)");

    const draft = render(<AdminSurveyEditor survey={{ ...survey, status: "draft", publishedAt: null }} />);
    expect(draft).toContain("Publier");
    expect(draft).toContain("Supprimer la question");
  });

  it("lists the surveys of an event, or says there is none", () => {
    expect(render(<SurveyList eventId="e1" surveys={[]} />)).toContain("Aucun sondage pour cet événement.");
    const html = render(
      <SurveyList
        eventId="e1"
        surveys={[{ id: "s1", title: "Quand viens-tu ?", audience: "participants", anonymous: true, status: "draft", opensAt: survey.opensAt, closesAt: survey.closesAt, questionCount: 1 }]}
      />,
    );
    expect(html).toContain('href="/admin/evenements/e1/sondages/s1"');
    expect(html).toContain("Brouillon");
    expect(html).toContain("Participants · 1 question ·");
  });

  it("shows a registrant's answers on the registration page", () => {
    const html = render(
      <RegistrationSurveys
        eventId="e1"
        surveys={[
          {
            surveyId: "s1",
            title: "Quand viens-tu ?",
            respondedAt: "2026-11-11T10:00:00+00:00",
            answers: [{ question: "Quels jours ?", kind: "multiple", choices: ["Samedi", "Dimanche"], text: null, rating: null }],
          },
        ]}
      />,
    );
    expect(html).toContain("Samedi, Dimanche");
    expect(labelledAnswer({ question: "Un mot ?", kind: "text", choices: [], text: "Hâte", rating: null })).toBe("Hâte");
  });

  it("reads and writes through the API", async () => {
    server.use(
      http.get(`${BASE}/admin/surveys/s1/results`, () => HttpResponse.json({ data: results, meta: {} })),
      http.get(`${BASE}/admin/events/e1/registrations/r1/surveys`, () => HttpResponse.json({ data: [{ surveyId: "s1" }], meta: {} })),
      http.post(`${BASE}/admin/surveys/s1/questions`, () => HttpResponse.json({ data: { id: "q9" }, meta: {} }, { status: 201 })),
      http.post(`${BASE}/admin/surveys/s2/questions`, () =>
        HttpResponse.json({ error: { code: "survey_question_label_invalid", message: "Une question de 1 à 200 caractères." } }, { status: 422 }),
      ),
    );
    await expect(fetchSurveyResults("s1")).resolves.toEqual(results);
    await expect(fetchRegistrationSurveys("e1", "r1")).resolves.toEqual([]);
    await expect(addQuestion("s1", { kind: "text", label: "Un mot ?", required: false, options: [] })).resolves.toEqual({ ok: true, id: "q9" });
    await expect(addQuestion("s2", { kind: "text", label: "", required: false, options: [] })).resolves.toEqual({
      ok: false,
      message: "Une question de 1 à 200 caractères.",
    });
  });

  it("keeps the small helpers honest", () => {
    expect(percent(2, 3)).toBe(67);
    expect(percent(0, 0)).toBe(0);
    expect(optionLines(" Samedi \n\nDimanche\n")).toEqual(["Samedi", "Dimanche"]);
    expect(fromLocalInput("")).toBeUndefined();
    expect(fromLocalInput("2026-11-16T17:30")).toMatch(/^2026-11-16T\d{2}:30:00\.000Z$/);
  });
});

/** Story 51.6: results readable with many respondents. */
describe("survey results by question and by member (story 51.6)", () => {
  const people: RespondentAnswers[] = [
    {
      userId: "u1",
      displayName: "Zoé",
      registrationId: "r1",
      respondedAt: "2026-11-11T09:00:00+00:00",
      answers: [
        { questionId: "coming", optionIds: ["yes"], text: null, rating: null },
        { questionId: "mark", optionIds: [], text: null, rating: 5 },
        { questionId: "word", optionIds: [], text: "Alors on danse", rating: null },
      ],
    },
    {
      userId: "u2",
      displayName: "Bob",
      registrationId: "r2",
      respondedAt: "2026-11-11T10:00:00+00:00",
      answers: [
        { questionId: "coming", optionIds: ["no"], text: null, rating: null },
        { questionId: "mark", optionIds: [], text: null, rating: 4 },
      ],
    },
    {
      userId: "u3",
      displayName: "Alice",
      registrationId: null,
      respondedAt: "2026-11-11T11:00:00+00:00",
      answers: [{ questionId: "coming", optionIds: ["yes"], text: null, rating: null }],
    },
  ];

  it("groups who answered what: by option, by mark from 5, texts with their author", () => {
    const [coming, mark, word] = results.questions;

    expect(answerGroups(coming, people).map((group) => [group.label, group.people.map((p) => p.respondent.displayName)])).toEqual([
      ["Oui", ["Zoé", "Alice"]],
      ["Non", ["Bob"]],
      ["Peut-être (retirée)", []],
    ]);
    expect(answerGroups(mark, people).map((group) => [group.key, group.people.length])).toEqual([
      ["5", 1],
      ["4", 1],
    ]);
    expect(answerGroups(word, people)[0].people).toEqual([{ respondent: people[0], text: "Alors on danse" }]);
  });

  it("filters the members by name, without accents, and by an answer", () => {
    expect(filterRespondents(people, { search: "zoe", choice: null }).map((p) => p.displayName)).toEqual(["Zoé"]);
    expect(filterRespondents(people, { search: "", choice: "coming:yes" }).map((p) => p.displayName)).toEqual(["Zoé", "Alice"]);
    expect(filterRespondents(people, { search: "bob", choice: "coming:yes" })).toEqual([]);
  });

  it("lets each option open who chose it, and keeps the totals first", () => {
    const html = render(<SurveyResultsView eventId="e1" respondents={people} results={results} />);

    expect(html).toContain("2 · 67 %");
    expect(html).toContain('aria-label="Voir qui a répondu « Oui »"');
    expect(html).toContain("Qui a répondu quoi (3)");
    expect(html).toContain("Lire les réponses (2)");
    // Folded until asked: the names are not listed yet, nor the texts.
    expect(html).not.toContain("Zoé");
    expect(html).not.toContain("Alors on danse");
  });

  it("shows a table of the members, and no member view for an anonymous survey", () => {
    const table = render(<RespondentList eventId="e1" questions={survey.questions} respondents={people} />);
    expect(table).toContain("3 sur 3");
    expect(table).toContain('<optgroup label="Tu viens ?">');
    expect(table.match(/<tr/g)).toHaveLength(4);
    expect(table).toContain("5 / 5");

    const views = (anonymous: boolean) =>
      render(
        <ResultsViews
          eventId="e1"
          missing={[{ userId: "u4", registrationId: "r4", displayName: "Dave" }]}
          onView={() => undefined}
          questions={survey.questions}
          respondents={anonymous ? null : people}
          results={{ ...results, anonymous }}
          view="respondents"
        />,
      );
    expect(views(false)).toContain("Par inscrit (3)");
    expect(views(false)).toContain("Pas encore répondu (1)");
    expect(views(true)).not.toContain("Par inscrit");
    // Asked for the member view, an anonymous survey falls back to its totals, with nobody named.
    expect(views(true)).toContain("4,5 / 5 de moyenne");
    expect(views(true)).not.toContain("Qui a répondu quoi");
  });
});
