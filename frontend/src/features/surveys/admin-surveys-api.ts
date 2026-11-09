import { apiErrorMessage } from "@/lib/api-error";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableNumberProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

import type { QuestionKind, SurveyAnswer } from "./surveys-api";

/** Stories 51.1 and 51.4: the admin side of the surveys. */
export type SurveyAudience = "registration" | "registrants" | "participants";
export type SurveyStatus = "draft" | "published";

export type AdminSurveySummary = {
  id: string;
  title: string;
  audience: SurveyAudience;
  anonymous: boolean;
  status: SurveyStatus;
  opensAt: string;
  closesAt: string;
  questionCount: number;
};

export type AdminOption = { id: string; label: string; position: number; retired: boolean };

export type AdminQuestion = { id: string; kind: QuestionKind; label: string; required: boolean; position: number; options: AdminOption[] };

export type AdminSurvey = {
  id: string;
  eventId: string;
  title: string;
  intro: string;
  audience: SurveyAudience;
  anonymous: boolean;
  sendEmail: boolean;
  status: SurveyStatus;
  opensAt: string;
  closesAt: string;
  publishedAt: string | null;
  /** Story 51.3: when the audience was told it opened, and the last reminder (automatic or by hand). */
  openingNotifiedAt: string | null;
  lastRemindedAt: string | null;
  questions: AdminQuestion[];
};

export type OptionResult = { id: string; label: string; retired: boolean; count: number };

export type RatingResult = { average: number | null; counts: number[] };

export type QuestionResult = {
  id: string;
  kind: QuestionKind;
  label: string;
  required: boolean;
  answerCount: number;
  options: OptionResult[];
  rating: RatingResult | null;
  /** null when the question is not a text one, or when the texts of an anonymous survey are hidden. */
  texts: string[] | null;
};

export type SurveyResults = {
  surveyId: string;
  eventId: string;
  title: string;
  anonymous: boolean;
  status: SurveyStatus;
  closesAt: string;
  respondentCount: number;
  audienceSize: number;
  textsHidden: boolean;
  questions: QuestionResult[];
};

export type RespondentAnswers = {
  userId: string;
  displayName: string;
  registrationId: string | null;
  respondedAt: string;
  answers: SurveyAnswer[];
};

export type NonRespondent = { userId: string; registrationId: string; displayName: string };

export type LabelledAnswer = { question: string; kind: QuestionKind; choices: string[]; text: string | null; rating: number | null };

export type AnsweredSurvey = { surveyId: string; title: string; respondedAt: string; answers: LabelledAnswer[] };

/** The fields of a survey as the form sends them; a missing field is left as it is. */
export type SurveyFields = {
  title?: string;
  intro?: string;
  audience?: SurveyAudience;
  anonymous?: boolean;
  sendEmail?: boolean;
  opensAt?: string;
  closesAt?: string;
};

export type MutationResult = { ok: true; id: string | null } | { ok: false; message: string };

export const SURVEY_TITLE_MAX = 120;
export const SURVEY_INTRO_MAX = 1000;
export const QUESTION_LABEL_MAX = 200;
export const OPTION_LABEL_MAX = 80;
/** Mirrors the API: the texts of an anonymous survey show from this many responses. */
export const ANONYMOUS_TEXT_THRESHOLD = 3;

export const AUDIENCE_LABELS: Record<SurveyAudience, string> = {
  registration: "À l'inscription",
  registrants: "Inscrits",
  participants: "Participants",
};

export const KIND_LABELS: Record<QuestionKind, string> = {
  single: "Choix unique",
  multiple: "Choix multiple",
  text: "Texte libre",
  rating: "Note de 1 à 5",
};

export function adminSurveysKey(eventId: string) {
  return ["admin-surveys", eventId] as const;
}

export function adminSurveyKey(surveyId: string) {
  return ["admin-survey", surveyId] as const;
}

export function surveyResultsKey(surveyId: string) {
  return ["admin-survey-results", surveyId] as const;
}

export function surveyRespondentsKey(surveyId: string) {
  return ["admin-survey-respondents", surveyId] as const;
}

export function surveyNonRespondentsKey(surveyId: string) {
  return ["admin-survey-non-respondents", surveyId] as const;
}

export function registrationSurveysKey(registrationId: string) {
  return ["admin-registration-surveys", registrationId] as const;
}

const AUDIENCES: readonly string[] = ["registration", "registrants", "participants"];
const STATUSES: readonly string[] = ["draft", "published"];
const KINDS: readonly string[] = ["single", "multiple", "text", "rating"];

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function isStringList(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((item) => typeof item === "string");
}

function isNumberList(v: unknown): v is number[] {
  return Array.isArray(v) && v.every((item) => typeof item === "number");
}

function isAudience(v: unknown): v is SurveyAudience {
  return typeof v === "string" && AUDIENCES.includes(v);
}

function isStatus(v: unknown): v is SurveyStatus {
  return typeof v === "string" && STATUSES.includes(v);
}

function isKind(v: unknown): v is QuestionKind {
  return typeof v === "string" && KINDS.includes(v);
}

export function isAdminSurveySummary(v: unknown): v is AdminSurveySummary {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "title") &&
    "audience" in v &&
    isAudience(v.audience) &&
    hasBooleanProp(v, "anonymous") &&
    "status" in v &&
    isStatus(v.status) &&
    hasStringProp(v, "opensAt") &&
    hasStringProp(v, "closesAt") &&
    hasNumberProp(v, "questionCount")
  );
}

function isAdminOption(v: unknown): v is AdminOption {
  return isObject(v) && hasStringProp(v, "id") && hasStringProp(v, "label") && hasNumberProp(v, "position") && hasBooleanProp(v, "retired");
}

function isAdminQuestion(v: unknown): v is AdminQuestion {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    "kind" in v &&
    isKind(v.kind) &&
    hasStringProp(v, "label") &&
    hasBooleanProp(v, "required") &&
    hasNumberProp(v, "position") &&
    "options" in v &&
    Array.isArray(v.options) &&
    v.options.every(isAdminOption)
  );
}

export function isAdminSurvey(v: unknown): v is AdminSurvey {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "eventId") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "intro") &&
    "audience" in v &&
    isAudience(v.audience) &&
    hasBooleanProp(v, "anonymous") &&
    hasBooleanProp(v, "sendEmail") &&
    "status" in v &&
    isStatus(v.status) &&
    hasStringProp(v, "opensAt") &&
    hasStringProp(v, "closesAt") &&
    hasNullableStringProp(v, "publishedAt") &&
    hasNullableStringProp(v, "openingNotifiedAt") &&
    hasNullableStringProp(v, "lastRemindedAt") &&
    "questions" in v &&
    Array.isArray(v.questions) &&
    v.questions.every(isAdminQuestion)
  );
}

function isOptionResult(v: unknown): v is OptionResult {
  return isObject(v) && hasStringProp(v, "id") && hasStringProp(v, "label") && hasBooleanProp(v, "retired") && hasNumberProp(v, "count");
}

function isRatingResult(v: unknown): v is RatingResult {
  return isObject(v) && hasNullableNumberProp(v, "average") && "counts" in v && isNumberList(v.counts);
}

function isQuestionResult(v: unknown): v is QuestionResult {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    "kind" in v &&
    isKind(v.kind) &&
    hasStringProp(v, "label") &&
    hasBooleanProp(v, "required") &&
    hasNumberProp(v, "answerCount") &&
    "options" in v &&
    Array.isArray(v.options) &&
    v.options.every(isOptionResult) &&
    "rating" in v &&
    (v.rating === null || isRatingResult(v.rating)) &&
    "texts" in v &&
    (v.texts === null || isStringList(v.texts))
  );
}

export function isSurveyResults(v: unknown): v is SurveyResults {
  return (
    isObject(v) &&
    hasStringProp(v, "surveyId") &&
    hasStringProp(v, "eventId") &&
    hasStringProp(v, "title") &&
    hasBooleanProp(v, "anonymous") &&
    "status" in v &&
    isStatus(v.status) &&
    hasStringProp(v, "closesAt") &&
    hasNumberProp(v, "respondentCount") &&
    hasNumberProp(v, "audienceSize") &&
    hasBooleanProp(v, "textsHidden") &&
    "questions" in v &&
    Array.isArray(v.questions) &&
    v.questions.every(isQuestionResult)
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

export function isRespondentAnswers(v: unknown): v is RespondentAnswers {
  return (
    isObject(v) &&
    hasStringProp(v, "userId") &&
    hasStringProp(v, "displayName") &&
    hasNullableStringProp(v, "registrationId") &&
    hasStringProp(v, "respondedAt") &&
    "answers" in v &&
    Array.isArray(v.answers) &&
    v.answers.every(isSurveyAnswer)
  );
}

export function isNonRespondent(v: unknown): v is NonRespondent {
  return isObject(v) && hasStringProp(v, "userId") && hasStringProp(v, "registrationId") && hasStringProp(v, "displayName");
}

function isLabelledAnswer(v: unknown): v is LabelledAnswer {
  return (
    isObject(v) &&
    hasStringProp(v, "question") &&
    "kind" in v &&
    isKind(v.kind) &&
    "choices" in v &&
    isStringList(v.choices) &&
    hasNullableStringProp(v, "text") &&
    hasNullableNumberProp(v, "rating")
  );
}

export function isAnsweredSurvey(v: unknown): v is AnsweredSurvey {
  return (
    isObject(v) &&
    hasStringProp(v, "surveyId") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "respondedAt") &&
    "answers" in v &&
    Array.isArray(v.answers) &&
    v.answers.every(isLabelledAnswer)
  );
}

function dataOf(payload: unknown): unknown {
  return isObject(payload) && "data" in payload ? payload.data : undefined;
}

async function readList<T>(url: string, guard: (v: unknown) => v is T): Promise<T[] | null> {
  try {
    const res = await apiFetch(url);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(guard) : null;
  } catch {
    return null;
  }
}

async function readOne<T>(url: string, guard: (v: unknown) => v is T): Promise<T | null> {
  try {
    const res = await apiFetch(url);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return guard(data) ? data : null;
  } catch {
    return null;
  }
}

/** A write: the id the API created (201), null for a 204, or the API's message. */
async function mutate(method: string, path: string, body?: unknown): Promise<MutationResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, {
      method,
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
    });
    if (res.status === 204) return { ok: true, id: null };
    const payload: unknown = await res.json().catch(() => null);
    if (res.ok) {
      const data = dataOf(payload);
      return { ok: true, id: isObject(data) && hasStringProp(data, "id") ? data.id : null };
    }
    return { ok: false, message: apiErrorMessage(payload) ?? "L'enregistrement a échoué." };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

export function fetchAdminSurveys(eventId: string): Promise<AdminSurveySummary[] | null> {
  return readList(`${env.apiBaseUrl}/admin/events/${eventId}/surveys`, isAdminSurveySummary);
}

export function fetchAdminSurvey(surveyId: string): Promise<AdminSurvey | null> {
  return readOne(`${env.apiBaseUrl}/admin/surveys/${surveyId}`, isAdminSurvey);
}

export function fetchSurveyResults(surveyId: string): Promise<SurveyResults | null> {
  return readOne(`${env.apiBaseUrl}/admin/surveys/${surveyId}/results`, isSurveyResults);
}

export function fetchSurveyRespondents(surveyId: string): Promise<RespondentAnswers[] | null> {
  return readList(`${env.apiBaseUrl}/admin/surveys/${surveyId}/respondents`, isRespondentAnswers);
}

export function fetchSurveyNonRespondents(surveyId: string): Promise<NonRespondent[] | null> {
  return readList(`${env.apiBaseUrl}/admin/surveys/${surveyId}/non-respondents`, isNonRespondent);
}

export function fetchRegistrationSurveys(eventId: string, registrationId: string): Promise<AnsweredSurvey[] | null> {
  return readList(`${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/surveys`, isAnsweredSurvey);
}

export function createSurvey(eventId: string, fields: SurveyFields): Promise<MutationResult> {
  return mutate("POST", `/admin/events/${eventId}/surveys`, fields);
}

export function reviseSurvey(surveyId: string, fields: SurveyFields): Promise<MutationResult> {
  return mutate("PATCH", `/admin/surveys/${surveyId}`, fields);
}

export function publishSurvey(surveyId: string): Promise<MutationResult> {
  return mutate("POST", `/admin/surveys/${surveyId}/publish`);
}

export function deleteSurvey(surveyId: string): Promise<MutationResult> {
  return mutate("DELETE", `/admin/surveys/${surveyId}`);
}

export type RemindResult = { ok: true; recipients: number } | { ok: false; message: string };

/** Story 51.3: reminds the members who have not answered; the API's message when it refuses (closed, too soon). */
export async function remindSurvey(surveyId: string): Promise<RemindResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/surveys/${surveyId}/remind`, { method: "POST" });
    const payload: unknown = await res.json().catch(() => null);
    const data = dataOf(payload);
    if (res.ok && isObject(data) && hasNumberProp(data, "recipients")) return { ok: true, recipients: data.recipients };
    return { ok: false, message: apiErrorMessage(payload) ?? "La relance n'a pas pu partir." };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

export function addQuestion(surveyId: string, question: { kind: QuestionKind; label: string; required: boolean; options: string[] }): Promise<MutationResult> {
  return mutate("POST", `/admin/surveys/${surveyId}/questions`, question);
}

export function reviseQuestion(surveyId: string, questionId: string, patch: { label?: string; required?: boolean; kind?: QuestionKind }): Promise<MutationResult> {
  return mutate("PATCH", `/admin/surveys/${surveyId}/questions/${questionId}`, patch);
}

export function deleteQuestion(surveyId: string, questionId: string): Promise<MutationResult> {
  return mutate("DELETE", `/admin/surveys/${surveyId}/questions/${questionId}`);
}

export function orderQuestions(surveyId: string, ids: string[]): Promise<MutationResult> {
  return mutate("PUT", `/admin/surveys/${surveyId}/questions/order`, { ids });
}

export function addOption(surveyId: string, questionId: string, label: string): Promise<MutationResult> {
  return mutate("POST", `/admin/surveys/${surveyId}/questions/${questionId}/options`, { label });
}

export function renameOption(surveyId: string, optionId: string, label: string): Promise<MutationResult> {
  return mutate("PATCH", `/admin/surveys/${surveyId}/options/${optionId}`, { label });
}

export function removeOption(surveyId: string, optionId: string): Promise<MutationResult> {
  return mutate("DELETE", `/admin/surveys/${surveyId}/options/${optionId}`);
}

/** The share of `count` in `total`, as a whole percentage; 0 when nobody answered. */
export function percent(count: number, total: number): number {
  return total === 0 ? 0 : Math.round((count / total) * 100);
}

/** An ISO date as a `datetime-local` value, in the browser's time zone. */
export function toLocalInput(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** A `datetime-local` value as ISO 8601, or undefined when empty or invalid (the API then keeps its default). */
export function fromLocalInput(value: string): string | undefined {
  if (value === "") return undefined;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString();
}
