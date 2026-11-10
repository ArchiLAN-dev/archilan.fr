"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { BellRing, ChevronDown, Lock, Search, Star } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";

import {
  adminSurveyKey,
  ANONYMOUS_TEXT_THRESHOLD,
  fetchSurveyNonRespondents,
  fetchSurveyRespondents,
  fetchSurveyResults,
  percent,
  remindSurvey,
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

type PanelProps = {
  surveyId: string;
  eventId: string;
  anonymous: boolean;
  questions: AdminQuestion[];
  /** Story 51.3: undefined for a draft, which is not reminded; null when no reminder went out yet. */
  lastRemindedAt?: string | null;
};

type View = "questions" | "respondents" | "missing";

/** Story 51.4: read at each opening (`staleTime: 0`), the screen always shows the current answers. */
export function AdminSurveyResultsPanel({ surveyId, eventId, anonymous, questions, lastRemindedAt }: PanelProps) {
  const [view, setView] = useState<View>("questions");
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
    <ResultsViews
      eventId={eventId}
      missing={missing.data ?? null}
      onView={setView}
      questions={questions}
      remind={lastRemindedAt !== undefined && (missing.data?.length ?? 0) > 0 ? <RemindButton lastRemindedAt={lastRemindedAt} surveyId={surveyId} /> : null}
      respondents={anonymous ? null : (respondents.data ?? null)}
      results={results.data}
      view={view}
    />
  );
}

type ViewsProps = {
  results: SurveyResults;
  questions: AdminQuestion[];
  respondents: RespondentAnswers[] | null;
  missing: NonRespondent[] | null;
  eventId: string;
  view: View;
  onView: (view: View) => void;
  remind?: React.ReactNode;
};

/**
 * Story 51.6: the results in three views instead of one long page, so that 57 answers stay readable: each question
 * with its total and who answered what, a table of the respondents, and who has not answered yet.
 */
export function ResultsViews({ results, questions, respondents, missing, eventId, view, onView, remind }: ViewsProps) {
  const views: { value: View; label: string }[] = [
    { value: "questions", label: "Par question" },
    ...(results.anonymous ? [] : [{ value: "respondents" as const, label: `Par inscrit (${results.respondentCount})` }]),
    { value: "missing", label: `Pas encore répondu (${missing === null ? "…" : missing.length})` },
  ];
  const shown = results.anonymous && view === "respondents" ? "questions" : view;

  return (
    <div className="grid gap-5">
      <div aria-label="Vue des résultats" className="flex flex-wrap gap-1 border-b border-border" role="group">
        {views.map((option) => (
          <button
            aria-pressed={shown === option.value}
            className={`-mb-px border-b-2 px-3 py-2 text-sm transition-colors ${
              shown === option.value ? "border-accent font-semibold text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
            }`}
            key={option.value}
            onClick={() => onView(option.value)}
            type="button"
          >
            {option.label}
          </button>
        ))}
      </div>
      {shown === "questions" ? <SurveyResultsView eventId={eventId} respondents={respondents} results={results} /> : null}
      {shown === "respondents" ? (
        respondents === null ? (
          <p className="text-sm text-muted-foreground">Chargement…</p>
        ) : (
          <RespondentList eventId={eventId} questions={questions} respondents={respondents} />
        )
      ) : null}
      {shown === "missing" ? (
        missing === null ? (
          <p className="text-sm text-muted-foreground">Chargement…</p>
        ) : (
          <NonRespondentList eventId={eventId} members={missing}>
            {remind}
          </NonRespondentList>
        )
      ) : null}
    </div>
  );
}

type ResultsProps = { results: SurveyResults; respondents?: RespondentAnswers[] | null; eventId?: string };

export function SurveyResultsView({ results, respondents = null, eventId = results.eventId }: ResultsProps) {
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
          <QuestionResultCard eventId={eventId} key={question.id} question={question} respondents={respondents} textsHidden={results.textsHidden} />
        ))}
      </ol>
    </section>
  );
}

export type AnswerGroup = { key: string; label: string; people: { respondent: RespondentAnswers; text?: string }[] };

/**
 * Story 51.6: who answered what to one question. A group per option (retired ones included when chosen), per mark
 * from 5 to 1, or one group of texts with their authors. Groups nobody is in are left out, except the options.
 */
export function answerGroups(question: QuestionResult, respondents: RespondentAnswers[]): AnswerGroup[] {
  const answerOf = (respondent: RespondentAnswers) => respondent.answers.find((answer) => answer.questionId === question.id);
  if (question.kind === "single" || question.kind === "multiple") {
    return question.options.map((option) => ({
      key: option.id,
      label: option.retired ? `${option.label} (retirée)` : option.label,
      people: respondents.filter((respondent) => answerOf(respondent)?.optionIds.includes(option.id)).map((respondent) => ({ respondent })),
    }));
  }
  if (question.kind === "rating") {
    return [5, 4, 3, 2, 1]
      .map((mark) => ({
        key: String(mark),
        label: `${mark} ★`,
        people: respondents.filter((respondent) => answerOf(respondent)?.rating === mark).map((respondent) => ({ respondent })),
      }))
      .filter((group) => group.people.length > 0);
  }
  const texts = respondents.flatMap((respondent) => {
    const text = answerOf(respondent)?.text;
    return text ? [{ respondent, text }] : [];
  });
  return texts.length > 0 ? [{ key: "texts", label: "Réponses", people: texts }] : [];
}

type CardProps = { question: QuestionResult; textsHidden: boolean; respondents: RespondentAnswers[] | null; eventId: string };

function QuestionResultCard({ question, textsHidden, respondents, eventId }: CardProps) {
  // Story 51.6: an option or a mark picked in the total narrows the detail to it, and opens it.
  const [picked, setPicked] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const detailed = respondents !== null;
  const groups = detailed ? answerGroups(question, respondents) : [];
  const shownGroups = picked === null ? groups : groups.filter((group) => group.key === picked);
  const pick = (key: string) => {
    setPicked(picked === key ? null : key);
    setOpen(true);
  };

  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="font-semibold text-foreground">{question.label}</h3>
        <span className="text-xs text-muted-foreground">
          {question.answerCount} réponse{question.answerCount > 1 ? "s" : ""}
        </span>
      </div>

      {question.kind === "single" || question.kind === "multiple" ? (
        <ul className="grid gap-1">
          {question.options.map((option) => (
            <li key={option.id}>
              <Pickable active={picked === option.id} enabled={detailed} label={option.label} onPick={() => pick(option.id)}>
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
              </Pickable>
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
          <ul className="grid gap-0.5">
            {question.rating.counts.map((count, index) => (
              // The marks are fixed, 1 to 5: their position is their identity.
              <li key={`mark-${index + 1}`}>
                <Pickable active={picked === String(index + 1)} enabled={detailed && count > 0} label={`${index + 1} ★`} onPick={() => pick(String(index + 1))}>
                  <span className="grid grid-cols-[3rem_1fr_3rem] items-center gap-2 text-xs text-muted-foreground">
                    <span>{index + 1} ★</span>
                    <Bar value={percent(count, question.answerCount)} />
                    <span className="text-right">{count}</span>
                  </span>
                </Pickable>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {question.kind === "text" && !detailed ? (
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

      {detailed ? (
        <div className="grid gap-2 border-t border-border pt-3">
          <button
            aria-expanded={open}
            className="flex w-fit items-center gap-1.5 text-sm font-semibold text-accent-text hover:underline"
            onClick={() => setOpen(!open)}
            type="button"
          >
            <ChevronDown aria-hidden className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
            {question.kind === "text" ? "Lire les réponses" : "Qui a répondu quoi"} ({question.answerCount})
          </button>
          {picked !== null ? (
            <button className="w-fit text-xs text-muted-foreground hover:text-foreground" onClick={() => setPicked(null)} type="button">
              Filtré sur « {groups.find((group) => group.key === picked)?.label} » - tout afficher
            </button>
          ) : null}
          {open ? <AnswerDetail eventId={eventId} groups={shownGroups} kind={question.kind} /> : null}
        </div>
      ) : null}
    </li>
  );
}

function Pickable({ enabled, active, label, onPick, children }: { enabled: boolean; active: boolean; label: string; onPick: () => void; children: React.ReactNode }) {
  if (!enabled) return <div className="grid gap-1 px-2 py-1">{children}</div>;
  return (
    <button
      aria-label={`Voir qui a répondu « ${label} »`}
      aria-pressed={active}
      className={`grid w-full gap-1 rounded-md px-2 py-1 text-left transition-colors ${active ? "bg-accent/10 ring-1 ring-accent" : "hover:bg-surface-2"}`}
      onClick={onPick}
      type="button"
    >
      {children}
    </button>
  );
}

function AnswerDetail({ groups, kind, eventId }: { groups: AnswerGroup[]; kind: QuestionResult["kind"]; eventId: string }) {
  if (groups.every((group) => group.people.length === 0)) return <p className="text-sm text-muted-foreground">Aucune réponse.</p>;
  if (kind === "text") {
    return (
      <ul className="grid gap-2">
        {groups.flatMap((group) =>
          group.people.map(({ respondent, text }) => (
            <li className="grid gap-1 rounded-lg border border-border bg-background p-3" key={respondent.userId}>
              <PersonLink eventId={eventId} respondent={respondent} />
              <p className="whitespace-pre-line text-sm text-foreground">{text}</p>
            </li>
          )),
        )}
      </ul>
    );
  }
  return (
    <dl className="grid gap-3">
      {groups.map((group) => (
        <div className="grid gap-1.5" key={group.key}>
          <dt className="text-sm font-semibold text-foreground">
            {group.label} <span className="font-normal text-muted-foreground">({group.people.length})</span>
          </dt>
          <dd>
            {group.people.length === 0 ? (
              <span className="text-xs text-muted-foreground">Personne</span>
            ) : (
              <ul className="flex flex-wrap gap-1.5">
                {group.people.map(({ respondent }) => (
                  <li className="rounded-md border border-border px-2 py-0.5" key={respondent.userId}>
                    <PersonLink eventId={eventId} respondent={respondent} />
                  </li>
                ))}
              </ul>
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function PersonLink({ respondent, eventId }: { respondent: RespondentAnswers; eventId: string }) {
  if (respondent.registrationId === null) return <span className="text-sm text-foreground">{respondent.displayName}</span>;
  return (
    <Link className="text-sm text-accent-text hover:underline" href={`/admin/evenements/${eventId}/inscriptions/${respondent.registrationId}`}>
      {respondent.displayName}
    </Link>
  );
}

function Bar({ value }: { value: number }) {
  return (
    <div aria-hidden className="h-2 overflow-hidden rounded-full bg-background">
      <div className="h-full rounded-full bg-accent" style={{ width: `${value}%` }} />
    </div>
  );
}

export function NonRespondentList({ eventId, members, children }: { eventId: string; members: NonRespondent[]; children?: React.ReactNode }) {
  return (
    <section aria-labelledby="missing-heading" className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="missing-heading">
        Pas encore répondu ({members.length})
      </h2>
      {children}
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

/** Story 51.3: a reminder to those who have not answered, by mail (when the survey sends some) and in the bell. */
export function RemindButton({ surveyId, lastRemindedAt }: { surveyId: string; lastRemindedAt: string | null }) {
  const queryClient = useQueryClient();
  const [state, setState] = useState<{ kind: "idle" | "sending" } | { kind: "sent"; recipients: number } | { kind: "error"; message: string }>({ kind: "idle" });

  async function remind() {
    setState({ kind: "sending" });
    const result = await remindSurvey(surveyId);
    setState(result.ok ? { kind: "sent", recipients: result.recipients } : { kind: "error", message: result.message });
    await queryClient.invalidateQueries({ queryKey: adminSurveyKey(surveyId) });
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <button
        className={buttonVariants({ variant: "secondary" })}
        disabled={state.kind === "sending" || state.kind === "sent"}
        onClick={() => void remind()}
        type="button"
      >
        <BellRing aria-hidden className="size-4" />
        Relancer les non-répondants
      </button>
      {state.kind === "sent" ? (
        <span className="text-sm text-success" role="status">
          {state.recipients} personne{state.recipients > 1 ? "s" : ""} relancée{state.recipients > 1 ? "s" : ""}.
        </span>
      ) : state.kind === "error" ? (
        <span className="text-sm text-danger" role="alert">
          {state.message}
        </span>
      ) : lastRemindedAt !== null ? (
        <span className="text-xs text-muted-foreground">Dernière relance le {MOMENT.format(new Date(lastRemindedAt))}. Une relance par 24 h au plus.</span>
      ) : (
        <span className="text-xs text-muted-foreground">Une relance automatique part 48 h avant la fermeture.</span>
      )}
    </div>
  );
}

/** Lower case, without accents: « Zoé » is found with « zoe ». */
function folded(value: string): string {
  return value.normalize("NFD").replace(/\p{Diacritic}/gu, "").toLowerCase();
}

/** A choice to filter on, written `questionId:optionId`. */
export type AnswerFilter = { search: string; choice: string | null };

/** Story 51.6: the respondents whose name holds the search and who picked the chosen option, if any. */
export function filterRespondents(respondents: RespondentAnswers[], { search, choice }: AnswerFilter): RespondentAnswers[] {
  const needle = folded(search.trim());
  const [questionId, optionId] = choice === null ? [null, null] : choice.split(":");
  return respondents.filter(
    (respondent) =>
      (needle === "" || folded(respondent.displayName).includes(needle)) &&
      (questionId === null || respondent.answers.some((answer) => answer.questionId === questionId && answer.optionIds.includes(optionId ?? ""))),
  );
}

/** Story 51.6: a compact table, one row per respondent and one column per question, with a search and a filter. */
export function RespondentList({ eventId, questions, respondents }: { eventId: string; questions: AdminQuestion[]; respondents: RespondentAnswers[] }) {
  const [search, setSearch] = useState("");
  const [choice, setChoice] = useState<string | null>(null);
  const rows = filterRespondents(respondents, { search, choice });
  const choiceQuestions = questions.filter((question) => question.kind === "single" || question.kind === "multiple");

  return (
    <section aria-labelledby="respondents-heading" className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="respondents-heading">
        Réponses par inscrit ({respondents.length})
      </h2>
      {respondents.length === 0 ? (
        <p className="text-sm text-muted-foreground">Personne n&apos;a encore répondu.</p>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex min-h-9 items-center gap-2 rounded-md border border-border bg-background px-2.5">
              <Search aria-hidden className="size-4 text-muted-foreground" />
              <span className="sr-only">Chercher un inscrit</span>
              <input
                className="w-44 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Chercher un pseudo"
                type="search"
                value={search}
              />
            </label>
            {choiceQuestions.length > 0 ? (
              <label className="flex items-center gap-2 text-sm text-muted-foreground">
                <span className="sr-only">Filtrer par réponse</span>
                <select
                  className="min-h-9 max-w-80 rounded-md border border-border bg-background px-2 text-sm text-foreground"
                  onChange={(event) => setChoice(event.target.value === "" ? null : event.target.value)}
                  value={choice ?? ""}
                >
                  <option value="">Toutes les réponses</option>
                  {choiceQuestions.map((question) => (
                    <optgroup key={question.id} label={question.label}>
                      {question.options.map((option) => (
                        <option key={option.id} value={`${question.id}:${option.id}`}>
                          {option.label}
                        </option>
                      ))}
                    </optgroup>
                  ))}
                </select>
              </label>
            ) : null}
            <span className="text-xs text-muted-foreground" role="status">
              {rows.length} sur {respondents.length}
            </span>
          </div>
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full min-w-max border-collapse text-sm">
              <thead className="bg-surface text-left text-xs text-muted-foreground">
                <tr>
                  <th className="sticky left-0 z-10 bg-surface px-3 py-2 font-semibold" scope="col">
                    Inscrit
                  </th>
                  {questions.map((question) => (
                    <th className="max-w-56 px-3 py-2 font-semibold" key={question.id} scope="col" title={question.label}>
                      <span className="line-clamp-2">{question.label}</span>
                    </th>
                  ))}
                  <th className="px-3 py-2 font-semibold" scope="col">
                    Répondu le
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((respondent) => (
                  <tr className="align-top hover:bg-surface/60" key={respondent.userId}>
                    <th className="sticky left-0 z-10 bg-background px-3 py-2 text-left font-normal" scope="row">
                      <PersonLink eventId={eventId} respondent={respondent} />
                    </th>
                    {questions.map((question) => {
                      const text = answerText(question, respondent.answers.find((answer) => answer.questionId === question.id));
                      return (
                        <td className="max-w-56 px-3 py-2 text-foreground" key={question.id} title={text}>
                          <span className="line-clamp-2 whitespace-pre-line">{text}</span>
                        </td>
                      );
                    })}
                    <td className="whitespace-nowrap px-3 py-2 text-xs text-muted-foreground">{MOMENT.format(new Date(respondent.respondedAt))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {rows.length === 0 ? <p className="px-3 py-6 text-center text-sm text-muted-foreground">Aucun inscrit pour ces filtres.</p> : null}
          </div>
        </>
      )}
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
