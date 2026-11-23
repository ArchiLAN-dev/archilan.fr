"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Flag } from "lucide-react";

import { getEventForecast, type CalibrationSlot, type EventForecast, type ForecastSlot } from "./event-forecast-api";

/** Story 56.8: the forecast follows the bridge's progress; a minute is plenty. */
const REFRESH_MS = 60_000;

const TIME = new Intl.DateTimeFormat("fr-FR", { weekday: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

export function formatFinish(iso: string): string {
  return TIME.format(new Date(iso));
}

function formatHours(hours: number): string {
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: hours < 10 ? 1 : 0 }).format(hours)} h`;
}

/** "+50 %" when it took longer than expected, "-20 %" when shorter. */
export function formatGap(gap: number): string {
  const percent = Math.round(gap * 100);
  return `${percent > 0 ? "+" : ""}${percent} %`;
}

type Props = { eventId: string };

export function EventForecastPanel({ eventId }: Props) {
  const { data, isPending } = useQuery({
    queryKey: ["admin-event-forecast", eventId],
    queryFn: () => getEventForecast(eventId),
    staleTime: REFRESH_MS / 2,
    refetchInterval: REFRESH_MS,
  });

  if (isPending) return null;
  if (!data) return <p className="text-sm text-danger">La prévision n&apos;a pas pu être chargée.</p>;

  return <EventForecastView eventId={eventId} forecast={data} />;
}

type ViewProps = { eventId: string; forecast: EventForecast };

/** Story 56.8: the live forecast of the running session, and the expected against real durations. Pure. */
export function EventForecastView({ eventId, forecast }: ViewProps) {
  const { live, calibration } = forecast;
  if (live === null && calibration.slots.length === 0) return null;

  return (
    <div className="grid min-w-0 gap-4">
      {live !== null ? <LiveForecast endsAt={forecast.endsAt} eventId={eventId} slots={live.slots} /> : null}
      {calibration.slots.length > 0 ? <Calibration meanGap={calibration.meanGap} slots={calibration.slots} /> : null}
    </div>
  );
}

function LiveForecast({ eventId, endsAt, slots }: { eventId: string; endsAt: string; slots: ForecastSlot[] }) {
  const toRelease = slots.filter((s) => s.toRelease).length;

  return (
    <section aria-labelledby="forecast-title" className="grid min-w-0 gap-3 rounded-lg border border-border bg-surface p-5">
      <header className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="forecast-title">
          En direct
        </h2>
        <p className="text-sm text-muted-foreground">
          Heure de fin de chaque slot au rythme de sa dernière heure de jeu (au rythme estimé du jeu s&apos;il a trop peu joué). Fin de
          l&apos;événement : {formatFinish(endsAt)}.
        </p>
        {toRelease > 0 ? (
          <p className="text-sm text-foreground">
            {toRelease} slot{toRelease > 1 ? "s" : ""} à libérer : release ou Passeur depuis{" "}
            <Link className="font-semibold text-accent-text underline" href={`/admin/evenements/${eventId}/session`}>
              Run et sessions
            </Link>
            .
          </p>
        ) : null}
      </header>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[36rem] text-left text-sm">
          <thead className="text-xs uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-1.5 pr-3 font-semibold">Slot</th>
              <th className="py-1.5 pr-3 font-semibold">Checks</th>
              <th className="py-1.5 pr-3 font-semibold">Rythme</th>
              <th className="py-1.5 pr-3 font-semibold">Fin estimée</th>
              <th className="py-1.5 font-semibold">Objets des autres</th>
            </tr>
          </thead>
          <tbody>
            {slots.map((slot) => (
              <tr className="border-t border-border" key={slot.slotName}>
                <td className="py-1.5 pr-3">
                  <span className="font-semibold text-foreground">{slot.slotName}</span>
                  <span className="block text-xs text-muted-foreground">{slot.gameName}</span>
                </td>
                <td className="py-1.5 pr-3 tabular-nums">
                  {slot.checksDone}
                  {slot.locations !== null ? ` / ${slot.locations}` : ""}
                </td>
                <td className="py-1.5 pr-3 tabular-nums">
                  {slot.checksPerHour === null ? "?" : `${slot.checksPerHour} /h`}
                  {slot.paceSource === "estimated" ? <span className="block text-xs text-muted-foreground">estimé</span> : null}
                </td>
                <td className="py-1.5 pr-3">{finishCell(slot)}</td>
                <td className="py-1.5">
                  {slot.toRelease ? (
                    <span className="inline-flex items-center gap-1 font-semibold text-danger">
                      <Flag aria-hidden className="size-3.5" />À libérer ({slot.holdingProgression})
                    </span>
                  ) : (
                    (slot.holdingProgression ?? "?")
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function finishCell(slot: ForecastSlot): string {
  if (slot.goalReached) return "Goal atteint";
  if (slot.released) return "Libéré";
  return slot.finishAt === null ? "?" : formatFinish(slot.finishAt);
}

function Calibration({ slots, meanGap }: { slots: CalibrationSlot[]; meanGap: number | null }) {
  return (
    <section aria-labelledby="calibration-title" className="grid min-w-0 gap-3 rounded-lg border border-border bg-surface p-5">
      <header className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="calibration-title">
          Prévu et réel
        </h2>
        <p className="text-sm text-muted-foreground">
          Durée estimée à la génération contre durée réelle jusqu&apos;au goal.
          {meanGap !== null ? ` Écart moyen : ${formatGap(meanGap)}.` : ""}
        </p>
      </header>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[28rem] text-left text-sm">
          <thead className="text-xs uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-1.5 pr-3 font-semibold">Slot</th>
              <th className="py-1.5 pr-3 font-semibold">Prévu</th>
              <th className="py-1.5 pr-3 font-semibold">Réel</th>
              <th className="py-1.5 font-semibold">Écart</th>
            </tr>
          </thead>
          <tbody>
            {slots.map((slot) => (
              <tr className="border-t border-border" key={slot.slotName}>
                <td className="py-1.5 pr-3">
                  <span className="font-semibold text-foreground">{slot.slotName}</span>
                  <span className="block text-xs text-muted-foreground">{slot.gameName}</span>
                </td>
                <td className="py-1.5 pr-3 tabular-nums">{formatHours(slot.estimatedHours)}</td>
                <td className="py-1.5 pr-3 tabular-nums">{formatHours(slot.actualHours)}</td>
                <td className="py-1.5 tabular-nums">{formatGap(slot.gap)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
