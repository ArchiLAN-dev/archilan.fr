"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { MessageCircleQuestion } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMySurveys, mySurveysKey, type MySurvey } from "./surveys-api";

const CLOSING = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

export function surveyClosing(closesAt: string): string {
  return CLOSING.format(new Date(closesAt));
}

/**
 * Story 51.2: on an event's page, the surveys the organisers ask the member and that the member has not answered
 * yet. Nothing for a visitor, nor once every survey is answered.
 */
export function EventSurveyBanner({ eventId, eventSlug }: { eventId: string; eventSlug: string }) {
  const { user } = useAuth();
  const { data } = useQuery({
    // A visitor is asked nothing: no call, no 401 to refresh.
    enabled: user !== null,
    queryKey: mySurveysKey(eventId),
    queryFn: () => fetchMySurveys(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return <SurveyBannerList eventSlug={eventSlug} surveys={data ?? []} />;
}

export function SurveyBannerList({ eventSlug, surveys }: { eventSlug: string; surveys: MySurvey[] }) {
  const pending = surveys.filter((survey) => !survey.answered);
  if (pending.length === 0) return null;

  return (
    <section aria-label="Sondages de l'orga" className="grid max-w-xl gap-3 rounded-xl border border-accent/40 bg-accent/5 p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <MessageCircleQuestion aria-hidden className="size-4 text-accent-text" />
        {pending.length > 1 ? "L'orga a des questions pour toi" : "L'orga a une question pour toi"}
      </p>
      <ul className="grid gap-2">
        {pending.map((survey) => (
          <li className="flex flex-wrap items-center justify-between gap-3" key={survey.id}>
            <div className="grid gap-0.5">
              <span className="font-semibold text-foreground">{survey.title}</span>
              <span className="text-xs text-muted-foreground">Jusqu&apos;au {surveyClosing(survey.closesAt)}</span>
            </div>
            <Link
              className="inline-flex min-h-10 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
              href={`/evenements/${eventSlug}/sondages/${survey.id}`}
            >
              Répondre
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
