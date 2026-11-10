"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ClipboardList, Lock, Plus } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import {
  AUDIENCE_LABELS,
  adminSurveysKey,
  createSurvey,
  fetchAdminSurveys,
  SURVEY_TITLE_MAX,
  type AdminSurveySummary,
  type SurveyAudience,
} from "./admin-surveys-api";

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

const PERIOD_FORMAT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Paris" });

export function surveyPeriod(opensAt: string, closesAt: string): string {
  return `du ${PERIOD_FORMAT.format(new Date(opensAt))} au ${PERIOD_FORMAT.format(new Date(closesAt))}`;
}

/** Story 51.4: the surveys of an event, and a new one. */
export function AdminEventSurveysPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const surveys = useQuery({ queryKey: adminSurveysKey(eventId), queryFn: () => fetchAdminSurveys(eventId), staleTime: 0, retry: false });

  return (
    <div className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Sondages de l&apos;événement</h1>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
          Une question aux inscrits avant l&apos;événement, ou un retour des participants après. Un sondage publié apparaît sur la page de
          l&apos;événement, pour son public, pendant sa période.
        </p>
      </header>
      <NewSurveyForm eventId={eventId} />
      {surveys.isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {surveys.data === null ? <p className="text-sm text-danger">Impossible de charger les sondages de cet événement.</p> : null}
      {surveys.data ? <SurveyList eventId={eventId} surveys={surveys.data} /> : null}
    </div>
  );
}

export function SurveyList({ eventId, surveys }: { eventId: string; surveys: AdminSurveySummary[] }) {
  if (surveys.length === 0) {
    return (
      <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
        <ClipboardList aria-hidden className="size-5" />
        Aucun sondage pour cet événement.
      </p>
    );
  }

  return (
    <ul className="grid gap-3">
      {surveys.map((survey) => (
        <li key={survey.id}>
          <Link
            className="grid gap-1 rounded-xl border border-border bg-surface p-4 transition-colors hover:border-accent"
            href={`/admin/evenements/${eventId}/sondages/${survey.id}`}
          >
            <span className="flex flex-wrap items-center gap-2 font-semibold text-foreground">
              {survey.title}
              <StatusBadge published={survey.status === "published"} />
              {survey.anonymous ? (
                <span className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground">
                  <Lock aria-hidden className="size-3" />
                  Anonyme
                </span>
              ) : null}
            </span>
            <span className="text-xs text-muted-foreground">
              {AUDIENCE_LABELS[survey.audience]} · {survey.questionCount} question{survey.questionCount > 1 ? "s" : ""} ·{" "}
              {surveyPeriod(survey.opensAt, survey.closesAt)}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function StatusBadge({ published }: { published: boolean }) {
  return published ? (
    <span className="rounded border border-success/40 bg-success/10 px-1.5 py-0.5 text-xs font-semibold text-success">Publié</span>
  ) : (
    <span className="rounded border border-border bg-background px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">Brouillon</span>
  );
}

function NewSurveyForm({ eventId }: { eventId: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [audience, setAudience] = useState<SurveyAudience>("registrants");
  const [anonymous, setAnonymous] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!open) {
    return (
      <button className={`${buttonVariants({ variant: "primary" })} w-fit`} onClick={() => setOpen(true)} type="button">
        <Plus aria-hidden className="size-4" />
        Nouveau sondage
      </button>
    );
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    const result = await createSurvey(eventId, { title: title.trim(), audience, anonymous });
    setPending(false);
    if (!result.ok) {
      setError(result.message);
      return;
    }
    if (result.id !== null) router.push(`/admin/evenements/${eventId}/sondages/${result.id}`);
  }

  return (
    <form className="grid max-w-xl gap-4 rounded-xl border border-border bg-surface p-5" onSubmit={(event) => void submit(event)}>
      <h2 className="font-heading text-lg font-semibold text-foreground">Nouveau sondage</h2>
      <label className="grid gap-1 text-sm text-foreground">
        Titre
        <input className={fieldClass} maxLength={SURVEY_TITLE_MAX} onChange={(event) => setTitle(event.target.value)} required value={title} />
      </label>
      <label className="grid gap-1 text-sm text-foreground">
        Public
        <select className={fieldClass} onChange={(event) => setAudience(audienceOf(event.target.value))} value={audience}>
          <option value="registrants">Inscrits (avant l&apos;événement)</option>
          <option value="participants">Participants (après l&apos;événement)</option>
          <option value="registration">À l&apos;inscription (étape du parcours)</option>
        </select>
      </label>
      <label className="flex items-start gap-2 text-sm text-foreground">
        <input checked={anonymous} className="mt-1 size-4" onChange={(event) => setAnonymous(event.target.checked)} type="checkbox" />
        <span>
          Anonyme
          <span className="block text-xs text-muted-foreground">
            On saura qui a répondu, pas qui a répondu quoi. Ne change plus une fois le sondage publié.
          </span>
        </span>
      </label>
      <p className="text-xs text-muted-foreground">La période est proposée selon le public ; elle se règle ensuite avec les questions.</p>
      {error === null ? null : (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <button className={buttonVariants({ variant: "primary" })} disabled={pending || title.trim() === ""} type="submit">
          Créer
        </button>
        <button className={buttonVariants({ variant: "ghost" })} onClick={() => setOpen(false)} type="button">
          Annuler
        </button>
      </div>
    </form>
  );
}

export function audienceOf(value: string): SurveyAudience {
  return value === "participants" ? "participants" : value === "registration" ? "registration" : "registrants";
}
