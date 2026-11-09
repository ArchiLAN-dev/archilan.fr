"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Lock } from "lucide-react";

import { adminSurveyKey, AUDIENCE_LABELS, fetchAdminSurvey } from "./admin-surveys-api";
import { StatusBadge, surveyPeriod } from "./admin-event-surveys";
import { AdminSurveyEditor } from "./admin-survey-editor";
import { AdminSurveyResultsPanel } from "./admin-survey-results";

type Tab = "questions" | "results";

/** Story 51.4: one survey, its questions and its results. A published survey opens on its results. */
export function AdminSurveyPage({ params }: { params: Promise<{ eventId: string; surveyId: string }> }) {
  const { eventId, surveyId } = use(params);
  const survey = useQuery({ queryKey: adminSurveyKey(surveyId), queryFn: () => fetchAdminSurvey(surveyId), staleTime: 0, retry: false });
  const [chosen, setChosen] = useState<Tab | null>(null);

  const back = (
    <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href={`/admin/evenements/${eventId}/sondages`}>
      <ArrowLeft aria-hidden className="size-4" />
      Sondages
    </Link>
  );

  if (survey.isLoading) {
    return (
      <div className="grid gap-6 p-6 md:p-8">
        {back}
        <p className="text-sm text-muted-foreground">Chargement…</p>
      </div>
    );
  }
  if (!survey.data) {
    return (
      <div className="grid gap-6 p-6 md:p-8">
        {back}
        <p className="text-sm text-danger">Sondage introuvable.</p>
      </div>
    );
  }

  const data = survey.data;
  const published = data.status === "published";
  const tab: Tab = chosen ?? (published ? "results" : "questions");

  return (
    <div className="grid gap-6 p-6 md:p-8">
      {back}
      <header className="grid gap-2">
        <h1 className="flex flex-wrap items-center gap-2 font-heading text-2xl font-bold text-foreground">
          {data.title}
          <StatusBadge published={published} />
        </h1>
        <p className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
          {AUDIENCE_LABELS[data.audience]} · {surveyPeriod(data.opensAt, data.closesAt)}
          {data.anonymous ? (
            <>
              {" "}
              · <Lock aria-hidden className="size-3.5" /> Anonyme
            </>
          ) : null}
        </p>
      </header>
      <div className="flex gap-1 border-b border-border" role="tablist">
        <TabButton active={tab === "questions"} label="Questions" onClick={() => setChosen("questions")} />
        <TabButton active={tab === "results"} label="Résultats" onClick={() => setChosen("results")} />
      </div>
      {tab === "questions" ? (
        <AdminSurveyEditor survey={data} />
      ) : (
        <AdminSurveyResultsPanel anonymous={data.anonymous} eventId={eventId} questions={data.questions} surveyId={data.id} />
      )}
    </div>
  );
}

function TabButton({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return (
    <button
      aria-selected={active}
      className={`-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition-colors ${
        active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
      }`}
      onClick={onClick}
      role="tab"
      type="button"
    >
      {label}
    </button>
  );
}
