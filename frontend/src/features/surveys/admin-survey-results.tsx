"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Lock, Star } from "lucide-react";

import {
  ANONYMOUS_TEXT_THRESHOLD,
  fetchSurveyNonRespondents,
  fetchSurveyRespondents,
  fetchSurveyResults,
  percent,
  surveyNonRespondentsKey,
  surveyRespondentsKey,
  surveyResultsKey,
  type AdminQuestion,
  type NonRespondent,
  type QuestionResult,
  type RespondentAnswers,
  type SurveyResults,
} from "./admin-surveys-api";
import type { SurveyAnswer } from "./surveys-api";

const MOMENT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Paris" });

/** Story 51.4: read at each opening (`staleTime: 0`), the screen always shows the current answers. */
export function AdminSurveyResultsPanel({ surveyId, eventId, anonymous, questions }: { surveyId: string; eventId: string; anonymous: boolean; questions: AdminQuestion[] }) {
  const results = useQuery({ queryKey: surveyResultsKey(surveyId), queryFn: () => fetchSurveyResults(surveyId), staleTime: 0, retry: false });
  const missing = useQuery({
    queryKey: surveyNonRespondentsKey(surveyId),
    queryFn: () => fetchSurveyNonRespondents(surveyId),
    staleTime: 0,
    retry: false,
  });
  const respondents = useQuery({
    queryKey: surveyRespondentsKey(surveyId),
    queryFn: () => fetchSurveyRespondents(surveyId),
    staleTime: 0,
    retry: false,
    enabled: !anonymous,
  });

  if (results.isLoading) return <p className="text-sm text-muted-foreground">Chargement…</p>;
  if (!results.data) return <p className="text-sm text-danger">Impossible de charger les résultats.</p>;

  return (
    <div className="grid gap-8">
      <SurveyResultsView results={results.data} />
      {missing.data ? <NonRespondentList eventId={eventId} members={missing.data} /> : null}
      {anonymous ? null : respondents.data ? <RespondentList eventId={eventId} questions={questions} respondents={respondents.data} /> : null}
    </div>
  );
}

export function SurveyResultsView({ results }: { results: SurveyResults }) {
  return (
    <section aria-labelledby="results-heading" className="grid gap-4">
      <header className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="results-heading">
          Résultats
        </h2>
        <p className="text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">{results.respondentCount}</span> réponse{results.respondentCount > 1 ? "s" : ""} sur{" "}
          {results.audienceSize} personne{results.audienceSize > 1 ? "s" : ""} concernée{results.audienceSize > 1 ? "s" : ""} (
          {percent(results.respondentCount, results.audienceSize)} %)
        </p>
        {results.anonymous ? (
          <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Lock aria-hidden className="size-3" />
            Sondage anonyme : les réponses ne se lisent pas par joueur, les textes sont triés par ordre alphabétique.
          </p>
        ) : null}
      </header>
      {results.respondentCount === 0 ? <p className="text-sm text-muted-foreground">Personne n&apos;a encore répondu.</p> : null}
      <ol className="grid gap-3">
        {results.questions.map((question) => (
          <QuestionResultCard key={question.id} question={question} textsHidden={results.textsHidden} />
        ))}
      </ol>
    </section>
  );
}

function QuestionResultCard({ question, textsHidden }: { question: QuestionResult; textsHidden: boolean }) {
  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="font-semibold text-foreground">{question.label}</h3>
        <span className="text-xs text-muted-foreground">
          {question.answerCount} réponse{question.answerCount > 1 ? "s" : ""}
        </span>
      </div>

      {question.kind === "single" || question.kind === "multiple" ? (
        <ul className="grid gap-2">
          {question.options.map((option) => (
            <li className="grid gap-1" key={option.id}>
              <div className="flex justify-between gap-2 text-sm">
                <span className={option.retired ? "text-muted-foreground" : "text-foreground"}>
                  {option.label}
                  {option.retired ? " (retirée)" : ""}
                </span>
                <span className="text-muted-foreground">
                  {option.count} · {percent(option.count, question.answerCount)} %
                </span>
              </div>
              <Bar value={percent(option.count, question.answerCount)} />
            </li>
          ))}
        </ul>
      ) : null}

      {question.kind === "rating" && question.rating ? (
        <div className="grid gap-2">
          <p className="flex items-center gap-1.5 text-sm text-foreground">
            <Star aria-hidden className="size-4 fill-accent-warm text-accent-warm" />
            {question.rating.average === null ? "Pas encore de note" : `${question.rating.average.toLocaleString("fr-FR")} / 5 de moyenne`}
          </p>
          <ul className="grid gap-1">
            {question.rating.counts.map((count, index) => (
              // The marks are fixed, 1 to 5: their position is their identity.
              <li className="grid grid-cols-[3rem_1fr_3rem] items-center gap-2 text-xs text-muted-foreground" key={`mark-${index + 1}`}>
                <span>{index + 1} ★</span>
                <Bar value={percent(count, question.answerCount)} />
                <span className="text-right">{count}</span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {question.kind === "text" ? (
        textsHidden ? (
          <p className="text-sm text-muted-foreground">
            Les réponses texte d&apos;un sondage anonyme sont visibles à partir de {ANONYMOUS_TEXT_THRESHOLD} réponses, pour qu&apos;on ne puisse
            pas reconnaître qui a écrit quoi.
          </p>
        ) : question.texts && question.texts.length > 0 ? (
          <ul className="grid gap-2">
            {question.texts.map((text, index) => (
              // Texts have no id, and two members may write the same words.
              <li className="whitespace-pre-line rounded-lg border border-border bg-background p-3 text-sm text-foreground" key={`${index}-${text}`}>
                {text}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">Aucune réponse.</p>
        )
      ) : null}
    </li>
  );
}

function Bar({ value }: { value: number }) {
  return (
    <div aria-hidden className="h-2 overflow-hidden rounded-full bg-background">
      <div className="h-full rounded-full bg-accent" style={{ width: `${value}%` }} />
    </div>
  );
}

export function NonRespondentList({ eventId, members }: { eventId: string; members: NonRespondent[] }) {
  return (
    <section aria-labelledby="missing-heading" className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="missing-heading">
        Pas encore répondu ({members.length})
      </h2>
      {members.length === 0 ? (
        <p className="text-sm text-muted-foreground">Tout le monde a répondu.</p>
      ) : (
        <ul className="flex flex-wrap gap-2">
          {members.map((member) => (
            <li key={member.registrationId}>
              <Link
                className="inline-flex rounded-lg border border-border px-2.5 py-1 text-sm text-foreground hover:border-accent"
                href={`/admin/evenements/${eventId}/inscriptions/${member.registrationId}`}
              >
                {member.displayName}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export function RespondentList({ eventId, questions, respondents }: { eventId: string; questions: AdminQuestion[]; respondents: RespondentAnswers[] }) {
  return (
    <section aria-labelledby="respondents-heading" className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="respondents-heading">
        Réponses par inscrit ({respondents.length})
      </h2>
      {respondents.length === 0 ? <p className="text-sm text-muted-foreground">Personne n&apos;a encore répondu.</p> : null}
      <ul className="grid gap-3">
        {respondents.map((respondent) => (
          <li className="grid gap-2 rounded-xl border border-border bg-surface p-4" key={respondent.userId}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              {respondent.registrationId === null ? (
                <span className="font-semibold text-foreground">{respondent.displayName}</span>
              ) : (
                <Link className="font-semibold text-accent-text hover:underline" href={`/admin/evenements/${eventId}/inscriptions/${respondent.registrationId}`}>
                  {respondent.displayName}
                </Link>
              )}
              <span className="text-xs text-muted-foreground">{MOMENT.format(new Date(respondent.respondedAt))}</span>
            </div>
            <dl className="grid gap-1 text-sm">
              {questions.map((question) => (
                <div className="grid gap-0.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-3" key={question.id}>
                  <dt className="text-muted-foreground">{question.label}</dt>
                  <dd className="whitespace-pre-line text-foreground">{answerText(question, respondent.answers.find((answer) => answer.questionId === question.id))}</dd>
                </div>
              ))}
            </dl>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** One answer in words: the chosen options, the text or the mark; a dash when the question was left blank. */
export function answerText(question: AdminQuestion, answer: SurveyAnswer | undefined): string {
  if (answer === undefined) return "-";
  if (answer.rating !== null) return `${answer.rating} / 5`;
  if (answer.text !== null) return answer.text;
  const labels = answer.optionIds.map((id) => question.options.find((option) => option.id === id)?.label ?? "Option supprimée");
  return labels.length === 0 ? "-" : labels.join(", ");
}
