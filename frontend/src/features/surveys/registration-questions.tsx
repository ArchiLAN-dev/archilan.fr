"use client";

import Link from "next/link";
import { use, useEffect } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { CheckCircle, CircleDashed } from "lucide-react";

import { fetchAuthProbe } from "@/features/events/events-api";
import { RegistrationStepper } from "@/features/events/registration-stepper";
import { SurveyResponseForm } from "./survey-response-form";
import { fetchMySurveys, fetchSurveyForm, mySurveysKey, surveyFormKey, type MySurvey, type SurveyAnswer, type SurveyForm } from "./surveys-api";

/**
 * Story 51.5: the open surveys of the event asked at registration that speak to the member. Read with the same query as
 * the event banner (51.2); `undefined` while it loads, so the steps never jump.
 */
export function useRegistrationSurveys(eventId: string): MySurvey[] | undefined {
  const query = useQuery({ queryKey: mySurveysKey(eventId), queryFn: () => fetchMySurveys(eventId), staleTime: 30_000, retry: false });
  if (query.data === undefined) return undefined;
  return (query.data ?? []).filter((survey) => survey.audience === "registration");
}

/** Where the games step leads: the questions when there are some, else the recap. */
export function afterGamesHref(eventSlug: string, registrationId: string, surveys: MySurvey[] | undefined): string {
  const base = `/evenements/${eventSlug}/inscription/${registrationId}`;
  return surveys !== undefined && surveys.length > 0 ? `${base}/questions` : `${base}/recap`;
}

/** The questions step: one survey at a time, the first not answered yet; then on to the recap. */
export function RegistrationQuestionsGate({ params }: { params: Promise<{ eventSlug: string; registrationId: string }> }) {
  const router = useRouter();
  const { eventSlug, registrationId } = use(params);
  const recapHref = `/evenements/${eventSlug}/inscription/${registrationId}/recap`;

  const authQuery = useQuery({ queryKey: ["registration-questions-auth"], queryFn: fetchAuthProbe, staleTime: 0, retry: false });
  useEffect(() => {
    if (authQuery.data === "unauthenticated") {
      router.replace(`/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/questions`);
    }
  }, [authQuery.data, eventSlug, registrationId, router]);

  const surveys = useRegistrationSurveys(eventSlug);
  // Nothing to ask (any more): straight to the recap.
  useEffect(() => {
    if (surveys !== undefined && surveys.length === 0) router.replace(recapHref);
  }, [recapHref, router, surveys]);

  const next = surveys?.find((survey) => !survey.answered);
  const formQuery = useQuery({
    queryKey: surveyFormKey(next?.id ?? "none"),
    queryFn: () => fetchSurveyForm(next?.id ?? ""),
    enabled: next !== undefined && authQuery.data === "authenticated",
    staleTime: 0,
    retry: false,
  });

  if (surveys === undefined || surveys.length === 0) return <div className="h-64 w-full animate-pulse rounded-xl bg-surface" />;

  return (
    <div className="grid gap-8">
      <RegistrationStepper currentStep={2} withQuestions />
      <QuestionsProgress surveys={surveys} />
      {next === undefined ? (
        <div className="grid gap-3 rounded-xl border border-success/40 bg-success/5 p-6">
          <p className="flex items-center gap-2 font-semibold text-foreground">
            <CheckCircle aria-hidden className="size-5 text-success" />
            Merci, tu as répondu aux questions de l&apos;orga.
          </p>
        </div>
      ) : formQuery.data?.kind === "ok" ? (
        <SurveyResponseForm eventSlug={eventSlug} form={formQuery.data.form} key={formQuery.data.form.id} nextLink={null} />
      ) : formQuery.data?.kind === "unavailable" ? (
        <p className="rounded-xl border border-border bg-surface p-6 text-muted-foreground">{formQuery.data.message}</p>
      ) : (
        <div className="h-64 w-full animate-pulse rounded-xl bg-surface" />
      )}
      <Link
        className="inline-flex min-h-10 w-full items-center justify-center rounded border border-accent px-5 text-sm font-semibold text-accent-text transition-colors hover:bg-accent/10 sm:w-fit"
        href={recapHref}
      >
        Continuer vers le récapitulatif →
      </Link>
    </div>
  );
}

export function QuestionsProgress({ surveys }: { surveys: MySurvey[] }) {
  return (
    <header className="grid gap-2">
      <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">Les questions de l&apos;orga</h1>
      <p className="text-sm text-muted-foreground">
        Elles nous aident à préparer l&apos;événement. Les questions marquées d&apos;un astérisque sont à remplir avant de confirmer ton
        inscription.
      </p>
      {surveys.length > 1 ? (
        <ul className="flex flex-wrap gap-2 text-xs">
          {surveys.map((survey) => (
            <li className="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-1 text-muted-foreground" key={survey.id}>
              {survey.answered ? <CheckCircle aria-hidden className="size-3 text-success" /> : <CircleDashed aria-hidden className="size-3" />}
              {survey.title}
            </li>
          ))}
        </ul>
      ) : null}
    </header>
  );
}

/** Story 51.5: on the recap, the member's answers to the questions at registration, and a way back to change them. */
export function RegistrationSurveysRecap({ eventSlug, registrationId }: { eventSlug: string; registrationId: string }) {
  const surveys = useRegistrationSurveys(eventSlug);
  if (surveys === undefined || surveys.length === 0) return null;
  const questionsHref = `/evenements/${eventSlug}/inscription/${registrationId}/questions`;

  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">Questions de l&apos;orga</h2>
      {surveys.map((survey) => (
        <SurveyRecapCard eventSlug={eventSlug} key={survey.id} questionsHref={questionsHref} survey={survey} />
      ))}
    </section>
  );
}

function SurveyRecapCard({ eventSlug, survey, questionsHref }: { eventSlug: string; survey: MySurvey; questionsHref: string }) {
  const form = useQuery({
    queryKey: surveyFormKey(survey.id),
    queryFn: () => fetchSurveyForm(survey.id),
    enabled: survey.answered && !survey.anonymous,
    staleTime: 0,
    retry: false,
  });

  return (
    <SurveyRecap
      editHref={survey.anonymous ? null : survey.answered ? `/evenements/${eventSlug}/sondages/${survey.id}` : questionsHref}
      form={form.data?.kind === "ok" ? form.data.form : null}
      survey={survey}
    />
  );
}

export function SurveyRecap({ survey, form, editHref }: { survey: MySurvey; form: SurveyForm | null; editHref: string | null }) {
  return (
    <div className="grid gap-3 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-2 font-semibold text-foreground">
          {survey.answered ? <CheckCircle aria-hidden className="size-4 text-success" /> : <CircleDashed aria-hidden className="size-4 text-accent-warm" />}
          {survey.title}
        </p>
        {editHref === null ? null : (
          <Link className="text-sm font-semibold text-accent-text hover:underline" href={editHref}>
            {survey.answered ? "Modifier mes réponses" : "Répondre"}
          </Link>
        )}
      </div>
      {!survey.answered ? <p className="text-sm text-accent-warm">Pas encore répondu.</p> : null}
      {survey.answered && survey.anonymous ? <p className="text-sm text-muted-foreground">Réponses anonymes, envoyées.</p> : null}
      {form !== null && form.myAnswers !== null ? (
        <dl className="grid gap-1 text-sm">
          {form.questions.map((question) => (
            <div className="grid gap-0.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-3" key={question.id}>
              <dt className="text-muted-foreground">{question.label}</dt>
              <dd className="whitespace-pre-line text-foreground">
                {answerLabel(
                  question.options,
                  form.myAnswers?.find((answer) => answer.questionId === question.id),
                )}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
    </div>
  );
}

/** One answer in words; a dash for a question left blank. */
export function answerLabel(options: { id: string; label: string }[], answer: SurveyAnswer | undefined): string {
  if (answer === undefined) return "-";
  if (answer.rating !== null) return `${answer.rating} / 5`;
  if (answer.text !== null) return answer.text;
  const labels = answer.optionIds.flatMap((id) => options.filter((option) => option.id === id).map((option) => option.label));
  return labels.length === 0 ? "-" : labels.join(", ");
}
