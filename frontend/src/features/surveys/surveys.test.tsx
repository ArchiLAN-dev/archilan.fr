import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { SurveyBannerList } from "./event-survey-banner";
import { answersToSend, initialAnswers, QuestionField, SurveyResponseForm } from "./survey-response-form";
import { fetchMySurveys, fetchSurveyForm, isSurveyForm, submitSurveyResponse, type MySurvey, type SurveyForm } from "./surveys-api";

const BASE = TEST_API_BASE_URL;

const pending: MySurvey = { id: "s1", title: "Quand viens-tu ?", audience: "registrants", anonymous: false, closesAt: "2026-11-20T17:00:00+00:00", answered: false };

const form: SurveyForm = {
  id: "s1",
  eventId: "e1",
  title: "Quand viens-tu ?",
  intro: "Pour préparer la salle.",
  anonymous: false,
  closesAt: "2026-11-20T17:00:00+00:00",
  answered: true,
  questions: [
    { id: "days", kind: "multiple", label: "Quels jours ?", required: true, options: [{ id: "sat", label: "Samedi" }, { id: "sun", label: "Dimanche" }] },
    { id: "word", kind: "text", label: "Un mot ?", required: false, options: [] },
    { id: "mark", kind: "rating", label: "Ta note ?", required: false, options: [] },
  ],
  myAnswers: [{ questionId: "days", optionIds: ["sun"], text: null, rating: null }],
};

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

/** Story 51.2: a member sees the organisers' surveys and answers them. */
describe("surveys", () => {
  it("accepts a complete form and refuses an unknown question kind", () => {
    expect(isSurveyForm(form)).toBe(true);
    expect(isSurveyForm({ ...form, myAnswers: null })).toBe(true);
    expect(isSurveyForm({ ...form, questions: [{ ...form.questions[0], kind: "slider" }] })).toBe(false);
  });

  it("asks only the surveys not answered yet, and nothing once all are", () => {
    const html = renderToStaticMarkup(<SurveyBannerList eventSlug="archilan-4" surveys={[pending, { ...pending, id: "s2", title: "Déjà fait", answered: true }]} />);
    expect(html).toContain("L&#x27;orga a une question pour toi");
    expect(html).toContain("Quand viens-tu ?");
    expect(html).toContain('href="/evenements/archilan-4/sondages/s1"');
    expect(html).not.toContain("Déjà fait");

    expect(renderToStaticMarkup(<SurveyBannerList eventSlug="archilan-4" surveys={[{ ...pending, answered: true }]} />)).toBe("");
  });

  it("starts from the member's answers and sends only what is filled", () => {
    const answers = initialAnswers(form);
    expect(answers.days?.optionIds).toEqual(["sun"]);
    expect(initialAnswers({ ...form, myAnswers: null })).toEqual({});

    const sent = answersToSend(form.questions, {
      ...answers,
      word: { questionId: "word", optionIds: [], text: "  Hâte !  ", rating: null },
      mark: { questionId: "mark", optionIds: [], text: null, rating: null },
    });
    expect(sent).toEqual([
      { questionId: "days", optionIds: ["sun"], text: null, rating: null },
      { questionId: "word", optionIds: [], text: "Hâte !", rating: null },
    ]);
  });

  it("draws each kind of question", () => {
    const noop = () => undefined;
    const choice = renderToStaticMarkup(<QuestionField answer={initialAnswers(form).days} onChange={noop} question={form.questions[0]!} />);
    expect(choice).toContain('type="checkbox"');
    expect(choice).toContain("obligatoire");
    expect(choice).toMatch(/checked=""[^>]*value="sun"/);

    const single = renderToStaticMarkup(<QuestionField answer={undefined} onChange={noop} question={{ ...form.questions[0]!, kind: "single" }} />);
    expect(single).toContain('type="radio"');

    expect(renderToStaticMarkup(<QuestionField answer={undefined} onChange={noop} question={form.questions[1]!} />)).toContain("<textarea");
    const rating = renderToStaticMarkup(<QuestionField answer={{ questionId: "mark", optionIds: [], text: null, rating: 3 }} onChange={noop} question={form.questions[2]!} />);
    expect(rating).toContain('aria-label="3 sur 5"');
    expect(rating).toMatch(/aria-pressed="true"[^>]*>|aria-label="3 sur 5" aria-pressed="true"/);
  });

  it("tells an anonymous survey apart, and does not reopen one already answered", () => {
    const anonymous = render(<SurveyResponseForm eventSlug="archilan-4" form={{ ...form, anonymous: true, answered: false, myAnswers: null }} />);
    expect(anonymous).toContain("Tes réponses sont anonymes");
    expect(anonymous).toContain("Envoyer mes réponses");

    const done = render(<SurveyResponseForm eventSlug="archilan-4" form={{ ...form, anonymous: true, answered: true, myAnswers: null }} />);
    expect(done).toContain("Tu as déjà répondu à ce sondage anonyme");
    expect(done).not.toContain("<form");

    expect(render(<SurveyResponseForm eventSlug="archilan-4" form={form} />)).toContain("Mettre à jour mes réponses");
  });

  it("reads the surveys and the form, and relays the API's refusals", async () => {
    server.use(
      http.get(`${BASE}/events/e1/my-surveys`, () => HttpResponse.json({ data: [pending, { id: "broken" }], meta: {} })),
      http.get(`${BASE}/surveys/s1/my-response`, () => HttpResponse.json({ data: form, meta: {} })),
      http.get(`${BASE}/surveys/s2/my-response`, () => HttpResponse.json({ error: { code: "survey_closed", message: "Ce sondage n'est pas ouvert." } }, { status: 409 })),
      http.put(`${BASE}/surveys/s1/my-response`, () => HttpResponse.json({ error: { code: "survey_answer_required", message: "Réponds aux questions obligatoires." } }, { status: 422 })),
      http.put(`${BASE}/surveys/s3/my-response`, () => new HttpResponse(null, { status: 204 })),
    );

    expect(await fetchMySurveys("e1")).toEqual([pending]);
    expect(await fetchSurveyForm("s1")).toEqual({ kind: "ok", form });
    expect(await fetchSurveyForm("s2")).toEqual({ kind: "unavailable", message: "Ce sondage n'est pas ouvert." });
    expect(await submitSurveyResponse("s1", [])).toBe("Réponds aux questions obligatoires.");
    expect(await submitSurveyResponse("s3", [])).toBeNull();
  });
});
