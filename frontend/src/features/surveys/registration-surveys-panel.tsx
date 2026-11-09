"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { ClipboardList } from "lucide-react";

import { fetchRegistrationSurveys, registrationSurveysKey, type AnsweredSurvey, type LabelledAnswer } from "./admin-surveys-api";

const MOMENT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Paris" });

/** Story 51.4: the nominative answers of this registrant, on the admin registration page. Nothing when there are none. */
export function RegistrationSurveysPanel({ eventId, registrationId }: { eventId: string; registrationId: string }) {
  const surveys = useQuery({
    queryKey: registrationSurveysKey(registrationId),
    queryFn: () => fetchRegistrationSurveys(eventId, registrationId),
    staleTime: 0,
    retry: false,
  });

  if (!surveys.data || surveys.data.length === 0) return null;

  return <RegistrationSurveys eventId={eventId} surveys={surveys.data} />;
}

export function RegistrationSurveys({ eventId, surveys }: { eventId: string; surveys: AnsweredSurvey[] }) {
  return (
    <section aria-labelledby="registration-surveys-heading" className="grid gap-3 rounded-xl border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground" id="registration-surveys-heading">
        <ClipboardList aria-hidden className="size-5 text-accent-text" />
        Sondages
      </h2>
      {surveys.map((survey) => (
        <div className="grid gap-2" key={survey.surveyId}>
          <p className="flex flex-wrap items-baseline justify-between gap-2">
            <Link className="font-semibold text-accent-text hover:underline" href={`/admin/evenements/${eventId}/sondages/${survey.surveyId}`}>
              {survey.title}
            </Link>
            <span className="text-xs text-muted-foreground">{MOMENT.format(new Date(survey.respondedAt))}</span>
          </p>
          <dl className="grid gap-1 text-sm">
            {survey.answers.map((answer) => (
              <div className="grid gap-0.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-3" key={answer.question}>
                <dt className="text-muted-foreground">{answer.question}</dt>
                <dd className="whitespace-pre-line text-foreground">{labelledAnswer(answer)}</dd>
              </div>
            ))}
          </dl>
        </div>
      ))}
    </section>
  );
}

export function labelledAnswer(answer: LabelledAnswer): string {
  if (answer.rating !== null) return `${answer.rating} / 5`;
  if (answer.text !== null) return answer.text;
  return answer.choices.length === 0 ? "-" : answer.choices.join(", ");
}
