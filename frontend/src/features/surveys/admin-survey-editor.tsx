"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, Plus, Send, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import type { QuestionKind } from "./surveys-api";
import {
  addOption,
  addQuestion,
  adminSurveyKey,
  adminSurveysKey,
  AUDIENCE_LABELS,
  deleteQuestion,
  deleteSurvey,
  fromLocalInput,
  KIND_LABELS,
  OPTION_LABEL_MAX,
  orderQuestions,
  publishSurvey,
  QUESTION_LABEL_MAX,
  removeOption,
  renameOption,
  reviseQuestion,
  reviseSurvey,
  SURVEY_INTRO_MAX,
  SURVEY_TITLE_MAX,
  toLocalInput,
  type AdminOption,
  type AdminQuestion,
  type AdminSurvey,
  type MutationResult,
} from "./admin-surveys-api";
import { audienceOf } from "./admin-event-surveys";

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

const KINDS: QuestionKind[] = ["single", "multiple", "text", "rating"];

function kindOf(value: string): QuestionKind {
  return KINDS.find((kind) => kind === value) ?? "single";
}

/** One write, then the survey read again; the API's message when it refuses. */
function useSurveyAction(survey: AdminSurvey) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function run(action: () => Promise<MutationResult>): Promise<boolean> {
    setPending(true);
    const result = await action();
    setPending(false);
    setError(result.ok ? null : result.message);
    await queryClient.invalidateQueries({ queryKey: adminSurveyKey(survey.id) });
    await queryClient.invalidateQueries({ queryKey: adminSurveysKey(survey.eventId) });
    return result.ok;
  }

  return { error, pending, run };
}

/** Stories 51.1 and 51.4: a survey as the admins write it. Audience, anonymity and question kinds lock once published. */
export function AdminSurveyEditor({ survey }: { survey: AdminSurvey }) {
  const router = useRouter();
  const { error, pending, run } = useSurveyAction(survey);
  const [confirm, setConfirm] = useState<"publish" | "delete" | null>(null);
  const published = survey.status === "published";

  async function move(index: number, delta: -1 | 1) {
    const ids = survey.questions.map((question) => question.id);
    const to = index + delta;
    if (to < 0 || to >= ids.length) return;
    [ids[index], ids[to]] = [ids[to], ids[index]];
    await run(() => orderQuestions(survey.id, ids));
  }

  return (
    <div className="grid gap-6">
      <SurveySettings key={`${survey.id}-${survey.status}`} published={published} run={run} survey={survey} />

      <section aria-labelledby="questions-heading" className="grid gap-3">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="questions-heading">
          Questions ({survey.questions.length})
        </h2>
        {survey.questions.length === 0 ? <p className="text-sm text-muted-foreground">Aucune question pour l&apos;instant.</p> : null}
        <ol className="grid gap-3">
          {survey.questions.map((question, index) => (
            <QuestionCard
              first={index === 0}
              key={question.id}
              last={index === survey.questions.length - 1}
              onMove={(delta) => void move(index, delta)}
              published={published}
              question={question}
              run={run}
              surveyId={survey.id}
            />
          ))}
        </ol>
        <NewQuestionForm run={run} surveyId={survey.id} />
      </section>

      {error === null ? null : (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      )}

      {published ? null : (
        <div className="flex flex-wrap gap-2 border-t border-border pt-4">
          <button className={buttonVariants({ variant: "primary" })} disabled={pending} onClick={() => setConfirm("publish")} type="button">
            <Send aria-hidden className="size-4" />
            Publier
          </button>
          <button className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={() => setConfirm("delete")} type="button">
            <Trash2 aria-hidden className="size-4" />
            Supprimer le brouillon
          </button>
        </div>
      )}

      <ConfirmDialog
        confirmLabel="Publier"
        description={`Il apparaîtra sur la page de l'événement pour le public « ${AUDIENCE_LABELS[survey.audience]} » pendant sa période. Le public, l'anonymat et le type des questions ne changeront plus.`}
        onConfirm={() => {
          void run(() => publishSurvey(survey.id)).then(() => setConfirm(null));
        }}
        onOpenChange={(open) => setConfirm(open ? "publish" : null)}
        open={confirm === "publish"}
        pending={pending}
        title="Publier ce sondage ?"
      />
      <ConfirmDialog
        confirmLabel="Supprimer"
        description="Le brouillon et ses questions disparaissent."
        onConfirm={() => {
          void run(() => deleteSurvey(survey.id)).then((ok) => {
            setConfirm(null);
            if (ok) router.push(`/admin/evenements/${survey.eventId}/sondages`);
          });
        }}
        onOpenChange={(open) => setConfirm(open ? "delete" : null)}
        open={confirm === "delete"}
        pending={pending}
        title="Supprimer ce brouillon ?"
        tone="danger"
      />
    </div>
  );
}

type Run = (action: () => Promise<MutationResult>) => Promise<boolean>;

function SurveySettings({ survey, published, run }: { survey: AdminSurvey; published: boolean; run: Run }) {
  const [title, setTitle] = useState(survey.title);
  const [intro, setIntro] = useState(survey.intro);
  const [audience, setAudience] = useState(survey.audience);
  const [anonymous, setAnonymous] = useState(survey.anonymous);
  const [sendEmail, setSendEmail] = useState(survey.sendEmail);
  const [opensAt, setOpensAt] = useState(() => toLocalInput(survey.opensAt));
  const [closesAt, setClosesAt] = useState(() => toLocalInput(survey.closesAt));
  const [saved, setSaved] = useState(false);

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const ok = await run(() =>
      reviseSurvey(survey.id, {
        title: title.trim(),
        intro,
        sendEmail,
        opensAt: fromLocalInput(opensAt),
        closesAt: fromLocalInput(closesAt),
        ...(published ? {} : { audience, anonymous }),
      }),
    );
    setSaved(ok);
  }

  return (
    <form className="grid gap-4 rounded-xl border border-border bg-surface p-5" onChange={() => setSaved(false)} onSubmit={(event) => void save(event)}>
      <label className="grid gap-1 text-sm text-foreground">
        Titre
        <input className={fieldClass} maxLength={SURVEY_TITLE_MAX} onChange={(event) => setTitle(event.target.value)} required value={title} />
      </label>
      <label className="grid gap-1 text-sm text-foreground">
        Introduction (facultative)
        <textarea
          className={`${fieldClass} min-h-20 py-2`}
          maxLength={SURVEY_INTRO_MAX}
          onChange={(event) => setIntro(event.target.value)}
          value={intro}
        />
      </label>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="grid gap-1 text-sm text-foreground">
          Ouverture
          <input className={fieldClass} onChange={(event) => setOpensAt(event.target.value)} type="datetime-local" value={opensAt} />
        </label>
        <label className="grid gap-1 text-sm text-foreground">
          Fermeture
          <input className={fieldClass} onChange={(event) => setClosesAt(event.target.value)} type="datetime-local" value={closesAt} />
        </label>
        <label className="grid gap-1 text-sm text-foreground">
          Public
          <select className={fieldClass} disabled={published} onChange={(event) => setAudience(audienceOf(event.target.value))} value={audience}>
            <option value="registrants">Inscrits (avant l&apos;événement)</option>
            <option value="participants">Participants (après l&apos;événement)</option>
            {audience === "registration" ? <option value="registration">{AUDIENCE_LABELS.registration}</option> : null}
          </select>
        </label>
        <div className="grid content-end gap-2">
          <label className="flex items-center gap-2 text-sm text-foreground">
            <input checked={anonymous} className="size-4" disabled={published} onChange={(event) => setAnonymous(event.target.checked)} type="checkbox" />
            Anonyme
          </label>
          <label className="flex items-center gap-2 text-sm text-foreground">
            <input checked={sendEmail} className="size-4" onChange={(event) => setSendEmail(event.target.checked)} type="checkbox" />
            Prévenir par mail à l&apos;ouverture
          </label>
        </div>
      </div>
      {published ? <p className="text-xs text-muted-foreground">Publié : le public et l&apos;anonymat ne changent plus.</p> : null}
      <div className="flex items-center gap-3">
        <button className={buttonVariants({ variant: "secondary" })} type="submit">
          Enregistrer
        </button>
        {saved ? (
          <span className="text-sm text-success" role="status">
            Enregistré.
          </span>
        ) : null}
      </div>
    </form>
  );
}

type QuestionCardProps = {
  surveyId: string;
  question: AdminQuestion;
  published: boolean;
  first: boolean;
  last: boolean;
  onMove: (delta: -1 | 1) => void;
  run: Run;
};

function QuestionCard({ surveyId, question, published, first, last, onMove, run }: QuestionCardProps) {
  const [label, setLabel] = useState(question.label);
  const [newOption, setNewOption] = useState("");
  const hasOptions = question.kind === "single" || question.kind === "multiple";

  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start gap-2">
        <input
          aria-label="Intitulé de la question"
          className={`${fieldClass} flex-1`}
          maxLength={QUESTION_LABEL_MAX}
          onBlur={() => {
            if (label.trim() === question.label || label.trim() === "") return;
            void run(() => reviseQuestion(surveyId, question.id, { label: label.trim() })).then((ok) => {
              if (!ok) setLabel(question.label);
            });
          }}
          onChange={(event) => setLabel(event.target.value)}
          value={label}
        />
        <select
          aria-label="Type de question"
          className={`${fieldClass} w-auto`}
          disabled={published}
          onChange={(event) => void run(() => reviseQuestion(surveyId, question.id, { kind: kindOf(event.target.value) }))}
          value={question.kind}
        >
          {KINDS.map((kind) => (
            <option key={kind} value={kind}>
              {KIND_LABELS[kind]}
            </option>
          ))}
        </select>
        <button aria-label="Monter" className={buttonVariants({ variant: "ghost" })} disabled={first} onClick={() => onMove(-1)} type="button">
          <ArrowUp aria-hidden className="size-4" />
        </button>
        <button aria-label="Descendre" className={buttonVariants({ variant: "ghost" })} disabled={last} onClick={() => onMove(1)} type="button">
          <ArrowDown aria-hidden className="size-4" />
        </button>
        {published ? null : (
          <button
            aria-label="Supprimer la question"
            className={buttonVariants({ variant: "ghost" })}
            onClick={() => void run(() => deleteQuestion(surveyId, question.id))}
            type="button"
          >
            <Trash2 aria-hidden className="size-4" />
          </button>
        )}
      </div>
      <label className="flex items-center gap-2 text-sm text-foreground">
        <input
          checked={question.required}
          className="size-4"
          onChange={(event) => void run(() => reviseQuestion(surveyId, question.id, { required: event.target.checked }))}
          type="checkbox"
        />
        Obligatoire
      </label>

      {hasOptions ? (
        <div className="grid gap-2 border-l-2 border-border pl-3">
          {question.options.map((option) => (
            <OptionRow key={option.id} option={option} published={published} run={run} surveyId={surveyId} />
          ))}
          <form
            className="flex gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              if (newOption.trim() === "") return;
              void run(() => addOption(surveyId, question.id, newOption.trim())).then((ok) => {
                if (ok) setNewOption("");
              });
            }}
          >
            <input
              aria-label="Nouvelle option"
              className={fieldClass}
              maxLength={OPTION_LABEL_MAX}
              onChange={(event) => setNewOption(event.target.value)}
              placeholder="Nouvelle option"
              value={newOption}
            />
            <button className={buttonVariants({ variant: "secondary" })} type="submit">
              <Plus aria-hidden className="size-4" />
              Ajouter
            </button>
          </form>
        </div>
      ) : null}
    </li>
  );
}

function OptionRow({ surveyId, option, published, run }: { surveyId: string; option: AdminOption; published: boolean; run: Run }) {
  const [label, setLabel] = useState(option.label);

  if (option.retired) {
    return <p className="text-sm text-muted-foreground line-through">{option.label} (retirée)</p>;
  }

  return (
    <div className="flex gap-2">
      <input
        aria-label="Option"
        className={fieldClass}
        maxLength={OPTION_LABEL_MAX}
        onBlur={() => {
          if (label.trim() === option.label || label.trim() === "") return;
          void run(() => renameOption(surveyId, option.id, label.trim())).then((ok) => {
            if (!ok) setLabel(option.label);
          });
        }}
        onChange={(event) => setLabel(event.target.value)}
        value={label}
      />
      <button
        className={buttonVariants({ variant: "ghost" })}
        onClick={() => void run(() => removeOption(surveyId, option.id))}
        title={published ? "Retirer : l'option n'est plus proposée, ses réponses restent" : "Supprimer l'option"}
        type="button"
      >
        {published ? "Retirer" : <Trash2 aria-label="Supprimer l'option" className="size-4" />}
      </button>
    </div>
  );
}

function NewQuestionForm({ surveyId, run }: { surveyId: string; run: Run }) {
  const [kind, setKind] = useState<QuestionKind>("single");
  const [label, setLabel] = useState("");
  const [required, setRequired] = useState(false);
  const [options, setOptions] = useState("");
  const hasOptions = kind === "single" || kind === "multiple";

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const ok = await run(() => addQuestion(surveyId, { kind, label: label.trim(), required, options: hasOptions ? optionLines(options) : [] }));
    if (ok) {
      setLabel("");
      setOptions("");
      setRequired(false);
    }
  }

  return (
    <form className="grid gap-3 rounded-xl border border-dashed border-border p-4" onSubmit={(event) => void submit(event)}>
      <h3 className="text-sm font-semibold text-foreground">Ajouter une question</h3>
      <div className="flex flex-wrap gap-2">
        <input
          aria-label="Intitulé"
          className={`${fieldClass} flex-1`}
          maxLength={QUESTION_LABEL_MAX}
          onChange={(event) => setLabel(event.target.value)}
          placeholder="Quand arrives-tu ?"
          required
          value={label}
        />
        <select aria-label="Type" className={`${fieldClass} w-auto`} onChange={(event) => setKind(kindOf(event.target.value))} value={kind}>
          {KINDS.map((value) => (
            <option key={value} value={value}>
              {KIND_LABELS[value]}
            </option>
          ))}
        </select>
      </div>
      {hasOptions ? (
        <label className="grid gap-1 text-sm text-foreground">
          Options, une par ligne (au moins deux pour publier)
          <textarea className={`${fieldClass} min-h-24 py-2`} onChange={(event) => setOptions(event.target.value)} value={options} />
        </label>
      ) : null}
      <label className="flex items-center gap-2 text-sm text-foreground">
        <input checked={required} className="size-4" onChange={(event) => setRequired(event.target.checked)} type="checkbox" />
        Obligatoire
      </label>
      <button className={`${buttonVariants({ variant: "secondary" })} w-fit`} disabled={label.trim() === ""} type="submit">
        <Plus aria-hidden className="size-4" />
        Ajouter la question
      </button>
    </form>
  );
}

/** The options typed one per line, blank lines dropped. */
export function optionLines(value: string): string[] {
  return value
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line !== "");
}
