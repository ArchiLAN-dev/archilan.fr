"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle, Lock, Star } from "lucide-react";

import { fetchAuthProbe } from "@/features/events/events-api";
import { surveyClosing } from "./event-survey-banner";
import {
  fetchSurveyForm,
  mySurveysKey,
  submitSurveyResponse,
  SURVEY_TEXT_MAX,
  surveyFormKey,
  type FormQuestion,
  type SurveyAnswer,
  type SurveyForm,
} from "./surveys-api";

type Answers = Partial<Record<string, SurveyAnswer>>;

const RATINGS = [1, 2, 3, 4, 5] as const;

/** Story 51.2: the page where a member answers a survey. A visitor is sent to log in first. */
export function SurveyResponseGate({ params }: { params: Promise<{ eventSlug: string; surveyId: string }> }) {
  const router = useRouter();
  const { eventSlug, surveyId } = use(params);

  const authQuery = useQuery({ queryKey: ["survey-auth-probe"], queryFn: fetchAuthProbe, staleTime: 0, retry: false });
  useEffect(() => {
    if (authQuery.data === "unauthenticated") {
      router.replace(`/connexion?returnTo=/evenements/${eventSlug}/sondages/${surveyId}`);
    }
  }, [authQuery.data, eventSlug, router, surveyId]);

  const formQuery = useQuery({
    queryKey: surveyFormKey(surveyId),
    queryFn: () => fetchSurveyForm(surveyId),
    enabled: authQuery.data === "authenticated",
    staleTime: 0,
    retry: false,
  });

  const back = (
    <Link className="inline-flex w-fit items-center text-sm text-muted-foreground transition-colors hover:text-foreground" href={`/evenements/${eventSlug}`}>
      &larr; Retour à l&apos;événement
    </Link>
  );

  if (formQuery.data === undefined) {
    return <div className="h-64 w-full animate-pulse rounded-xl bg-surface" />;
  }
  if (formQuery.data.kind === "unavailable") {
    return (
      <div className="grid gap-4">
        {back}
        <p className="rounded-xl border border-border bg-surface p-6 text-muted-foreground">{formQuery.data.message}</p>
      </div>
    );
  }

  return (
    <div className="grid gap-6">
      {back}
      <SurveyResponseForm eventSlug={eventSlug} form={formQuery.data.form} key={formQuery.data.form.id} />
    </div>
  );
}

/** The member's answers so far, by question: what the form starts from. */
export function initialAnswers(form: SurveyForm): Answers {
  const answers: Answers = {};
  for (const answer of form.myAnswers ?? []) answers[answer.questionId] = answer;
  return answers;
}

/** The answers worth sending: a question left blank is no answer. */
export function answersToSend(questions: FormQuestion[], answers: Answers): SurveyAnswer[] {
  return questions.flatMap((question) => {
    const answer = answers[question.id];
    if (answer === undefined) return [];
    const text = answer.text?.trim() ?? "";
    if (answer.optionIds.length === 0 && text === "" && answer.rating === null) return [];
    return [{ questionId: question.id, optionIds: answer.optionIds, text: text === "" ? null : text, rating: answer.rating }];
  });
}

type SurveyResponseFormProps = {
  eventSlug: string;
  form: SurveyForm;
  /** Story 51.5: inside the registration, the way on is the next step, not the event page. */
  nextLink?: { href: string; label: string } | null;
};

export function SurveyResponseForm({ eventSlug, form, nextLink }: SurveyResponseFormProps) {
  const queryClient = useQueryClient();
  const [answers, setAnswers] = useState<Answers>(() => initialAnswers(form));
  const [state, setState] = useState<{ kind: "idle" | "sending" | "sent" } | { kind: "error"; message: string }>({ kind: "idle" });
  const closing = surveyClosing(form.closesAt);

  if (form.anonymous && form.answered && state.kind !== "sent") {
    return (
      <SurveyHeader form={form}>
        <p className="rounded-xl border border-border bg-surface p-6 text-muted-foreground">
          Tu as déjà répondu à ce sondage anonyme. Merci ! Tes réponses ne se modifient pas, pour qu&apos;on ne puisse pas les relier à toi.
        </p>
      </SurveyHeader>
    );
  }

  if (state.kind === "sent") {
    return (
      <SurveyHeader form={form}>
        <div className="grid gap-3 rounded-xl border border-success/40 bg-success/5 p-6">
          <p className="flex items-center gap-2 font-semibold text-foreground">
            <CheckCircle aria-hidden className="size-5 text-success" />
            Merci, tes réponses sont enregistrées.
          </p>
          {form.anonymous ? null : <p className="text-sm text-muted-foreground">Tu peux les modifier jusqu&apos;au {closing}.</p>}
          {nextLink === null ? null : (
            <Link className="w-fit font-semibold text-accent-text hover:underline" href={nextLink?.href ?? `/evenements/${eventSlug}`}>
              {nextLink?.label ?? "Retour à l'événement"}
            </Link>
          )}
        </div>
      </SurveyHeader>
    );
  }

  function change(questionId: string, patch: Partial<SurveyAnswer>) {
    setAnswers((current) => ({
      ...current,
      [questionId]: { ...(current[questionId] ?? { questionId, optionIds: [], text: null, rating: null }), ...patch },
    }));
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState({ kind: "sending" });
    const error = await submitSurveyResponse(form.id, answersToSend(form.questions, answers));
    if (error !== null) {
      setState({ kind: "error", message: error });
      return;
    }
    void queryClient.invalidateQueries({ queryKey: mySurveysKey(form.eventId) });
    void queryClient.invalidateQueries({ queryKey: surveyFormKey(form.id) });
    setState({ kind: "sent" });
  }

  return (
    <SurveyHeader form={form}>
      {form.anonymous ? (
        <p className="flex items-start gap-2 rounded-xl border border-border bg-surface p-4 text-sm text-muted-foreground">
          <Lock aria-hidden className="mt-0.5 size-4 shrink-0 text-accent-text" />
          Tes réponses sont anonymes. On sait seulement que tu as répondu, pour ne pas te relancer. Elles ne se modifient pas une fois envoyées.
        </p>
      ) : null}
      <form className="grid gap-6" onSubmit={(event) => void submit(event)}>
        {form.questions.map((question) => (
          <QuestionField answer={answers[question.id]} key={question.id} onChange={(patch) => change(question.id, patch)} question={question} />
        ))}
        {state.kind === "error" ? (
          <p className="text-sm text-danger" role="alert">
            {state.message}
          </p>
        ) : null}
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-12 items-center rounded-lg bg-accent px-6 font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
            disabled={state.kind === "sending"}
            type="submit"
          >
            {state.kind === "sending" ? "Envoi…" : form.answered ? "Mettre à jour mes réponses" : "Envoyer mes réponses"}
          </button>
          <span className="text-xs text-muted-foreground">Ouvert jusqu&apos;au {closing}.</span>
        </div>
      </form>
    </SurveyHeader>
  );
}

function SurveyHeader({ form, children }: { form: SurveyForm; children: React.ReactNode }) {
  return (
    <article className="grid gap-6">
      <header className="grid gap-3">
        <h1 className="font-heading text-3xl font-bold text-foreground md:text-4xl">{form.title}</h1>
        {form.intro === "" ? null : (
          <p className="whitespace-pre-line rounded-xl border border-accent/40 bg-accent/5 p-4 text-base leading-7 text-foreground md:text-lg">{form.intro}</p>
        )}
      </header>
      {children}
    </article>
  );
}

type FieldProps = { question: FormQuestion; answer: SurveyAnswer | undefined; onChange: (patch: Partial<SurveyAnswer>) => void };

export function QuestionField({ question, answer, onChange }: FieldProps) {
  const chosen = answer?.optionIds ?? [];

  return (
    <fieldset className="grid gap-3 rounded-xl border border-border bg-surface p-5">
      <legend className="px-1 font-semibold text-foreground">
        {question.label}
        {question.required ? (
          <span aria-label="obligatoire" className="ml-1 text-danger">
            *
          </span>
        ) : null}
      </legend>

      {question.kind === "single" || question.kind === "multiple" ? (
        <div className="grid gap-2">
          {question.options.map((option) => {
            const checked = chosen.includes(option.id);
            return (
              <label className="flex min-h-10 cursor-pointer items-center gap-3 text-foreground" key={option.id}>
                <input
                  checked={checked}
                  className="size-4 accent-[var(--color-accent)]"
                  name={question.id}
                  onChange={() =>
                    onChange({
                      optionIds:
                        question.kind === "single" ? [option.id] : checked ? chosen.filter((id) => id !== option.id) : [...chosen, option.id],
                    })
                  }
                  type={question.kind === "single" ? "radio" : "checkbox"}
                  value={option.id}
                />
                {option.label}
              </label>
            );
          })}
        </div>
      ) : null}

      {question.kind === "text" ? (
        <textarea
          aria-label={question.label}
          className="min-h-24 rounded-lg border border-border bg-background px-3 py-2 text-foreground"
          maxLength={SURVEY_TEXT_MAX}
          onChange={(event) => onChange({ text: event.target.value })}
          value={answer?.text ?? ""}
        />
      ) : null}

      {question.kind === "rating" ? (
        <div aria-label={question.label} className="flex gap-1" role="group">
          {RATINGS.map((mark) => {
            const on = (answer?.rating ?? 0) >= mark;
            return (
              <button
                aria-label={`${mark} sur 5`}
                aria-pressed={answer?.rating === mark}
                className="rounded-lg p-1.5 transition-colors hover:bg-accent/10"
                key={mark}
                onClick={() => onChange({ rating: answer?.rating === mark ? null : mark })}
                type="button"
              >
                <Star aria-hidden className={`size-7 ${on ? "fill-accent-warm text-accent-warm" : "text-muted-foreground"}`} />
              </button>
            );
          })}
        </div>
      ) : null}
    </fieldset>
  );
}
