import { apiErrorMessage } from "@/lib/api-error";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableNumberProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 51.2: an open survey of an event that speaks to the member. */
export type MySurvey = { id: string; title: string; audience: string; anonymous: boolean; closesAt: string; answered: boolean };

export type QuestionKind = "single" | "multiple" | "text" | "rating";

export type FormOption = { id: string; label: string };

export type FormQuestion = { id: string; kind: QuestionKind; label: string; required: boolean; options: FormOption[] };

/** One answer, as the API reads and takes it back. */
export type SurveyAnswer = { questionId: string; optionIds: string[]; text: string | null; rating: number | null };

/** A survey as the member answers it; `myAnswers` is null for an anonymous survey, whose answers nobody reads back. */
export type SurveyForm = {
  id: string;
  eventId: string;
  title: string;
  intro: string;
  anonymous: boolean;
  closesAt: string;
  answered: boolean;
  questions: FormQuestion[];
  myAnswers: SurveyAnswer[] | null;
};

export type SurveyFormResult = { kind: "ok"; form: SurveyForm } | { kind: "unavailable"; message: string };

export const SURVEY_TEXT_MAX = 1000;

export function mySurveysKey(eventId: string) {
  return ["event-my-surveys", eventId] as const;
}

export function surveyFormKey(surveyId: string) {
  return ["survey-form", surveyId] as const;
}

const QUESTION_KINDS: readonly string[] = ["single", "multiple", "text", "rating"];

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function isStringList(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((item) => typeof item === "string");
}

function isQuestionKind(v: unknown): v is QuestionKind {
  return typeof v === "string" && QUESTION_KINDS.includes(v);
}

export function isMySurvey(v: unknown): v is MySurvey {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "audience") &&
    hasBooleanProp(v, "anonymous") &&
    hasStringProp(v, "closesAt") &&
    hasBooleanProp(v, "answered")
  );
}

function isFormOption(v: unknown): v is FormOption {
  return isObject(v) && hasStringProp(v, "id") && hasStringProp(v, "label");
}

function isFormQuestion(v: unknown): v is FormQuestion {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    "kind" in v &&
    isQuestionKind(v.kind) &&
    hasStringProp(v, "label") &&
    hasBooleanProp(v, "required") &&
    "options" in v &&
    Array.isArray(v.options) &&
    v.options.every(isFormOption)
  );
}

function isSurveyAnswer(v: unknown): v is SurveyAnswer {
  return (
    isObject(v) &&
    hasStringProp(v, "questionId") &&
    "optionIds" in v &&
    isStringList(v.optionIds) &&
    hasNullableStringProp(v, "text") &&
    hasNullableNumberProp(v, "rating")
  );
}

export function isSurveyForm(v: unknown): v is SurveyForm {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "eventId") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "intro") &&
    hasBooleanProp(v, "anonymous") &&
    hasStringProp(v, "closesAt") &&
    hasBooleanProp(v, "answered") &&
    "questions" in v &&
    Array.isArray(v.questions) &&
    v.questions.every(isFormQuestion) &&
    "myAnswers" in v &&
    (v.myAnswers === null || (Array.isArray(v.myAnswers) && v.myAnswers.every(isSurveyAnswer)))
  );
}

function dataOf(payload: unknown): unknown {
  return isObject(payload) && "data" in payload ? payload.data : undefined;
}

/** The open surveys of an event that speak to the member; null for a visitor or when the read failed. */
export async function fetchMySurveys(eventId: string): Promise<MySurvey[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/my-surveys`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isMySurvey) : null;
  } catch {
    return null;
  }
}

/** A survey to answer, or why it cannot be (closed, not for this member, gone), in the API's words. */
export async function fetchSurveyForm(surveyId: string): Promise<SurveyFormResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/surveys/${surveyId}/my-response`);
    const payload: unknown = await res.json().catch(() => null);
    if (!res.ok) return { kind: "unavailable", message: apiErrorMessage(payload) ?? "Ce sondage n'est pas disponible." };
    const data = dataOf(payload);
    return isSurveyForm(data) ? { kind: "ok", form: data } : { kind: "unavailable", message: "Ce sondage n'a pas pu être lu." };
  } catch {
    return { kind: "unavailable", message: "Impossible de contacter l'API." };
  }
}

/** null on success, the API's message otherwise. */
export async function submitSurveyResponse(surveyId: string, answers: SurveyAnswer[]): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/surveys/${surveyId}/my-response`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ answers }),
    });
    if (res.ok) return null;
    return apiErrorMessage(await res.json().catch(() => null)) ?? "Tes réponses n'ont pas pu être enregistrées.";
  } catch {
    return "Impossible de contacter l'API.";
  }
}
