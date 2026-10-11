"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Lock, LogIn, Megaphone, Minus, Plus, Users, type LucideIcon } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";

import { useAuth } from "@/features/auth/auth-context";
import { FriendIdentity } from "@/features/community/friend-identity";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchFriendsOpenRuns,
  FRIENDS_OPEN_RUNS_KEY,
  joinOpenRun,
  MAX_PITCH_LENGTH,
  MAX_SEATS_WANTED,
  setRunOpenness,
  type FriendsOpenRun,
} from "./friends-open-runs-api";
import { RUN_LISTINGS_KEY } from "./run-listings-api";
import type { RunOpenness } from "./types";

/** « 2 / 4 places », or how many already joined when the owner set no limit. */
export function seatsLabel(run: Pick<FriendsOpenRun, "seatsWanted" | "joined">): string {
  if (run.seatsWanted !== null) return `${run.joined} / ${run.seatsWanted} places`;
  if (run.joined === 0) return "Personne encore";
  return run.joined === 1 ? "1 joueur" : `${run.joined} joueurs`;
}

/**
 * « Parties de tes amis » (story 43.14): the draft runs friends opened to the member, joined in one click.
 * Nothing when there is none.
 */
export function FriendsOpenRuns({ compact = false }: { compact?: boolean }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const { data } = useQuery({
    queryKey: FRIENDS_OPEN_RUNS_KEY,
    queryFn: fetchFriendsOpenRuns,
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data || data.length === 0) return null;

  async function join(runId: string) {
    setBusy(runId);
    setMessage(null);
    const result = await joinOpenRun(runId);
    setBusy(null);
    // Story 43.19: a listed run is in both lists.
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: FRIENDS_OPEN_RUNS_KEY }),
      queryClient.invalidateQueries({ queryKey: RUN_LISTINGS_KEY }),
    ]);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    router.push(`/runs/${runId}`);
  }

  const Heading = compact ? "h3" : "h2";

  return (
    <section className={compact ? "grid gap-2" : "rounded-lg border border-border bg-surface p-4"}>
      <Heading className={compact ? "text-sm font-semibold text-foreground" : "mb-3 font-heading text-lg font-semibold text-foreground"}>
        Parties de tes amis
      </Heading>
      <ul className="grid gap-2" role="list">
        {data.map((run) => (
          <li className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2" key={run.runId}>
            <FriendIdentity card={run.owner} />
            <span className="min-w-0 flex-1 text-sm text-foreground">
              <strong>« {run.title} »</strong> <span className="text-xs text-muted-foreground">{seatsLabel(run)}</span>
            </span>
            <button
              className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
              disabled={busy !== null}
              onClick={() => void join(run.runId)}
              type="button"
            >
              <LogIn aria-hidden className="size-3.5" />
              Rejoindre
            </button>
          </li>
        ))}
      </ul>
      {message !== null ? (
        <p className="mt-2 text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
    </section>
  );
}

/** `2026-11-02T20:00` for a datetime-local field, from an ISO date (local time, as the owner reads it). */
export function toLocalInput(iso: string | null): string {
  if (iso === null) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const OPENNESS_CHOICES: { value: RunOpenness; title: string; hint: string; icon: LucideIcon }[] = [
  { value: "invite", title: "Sur invitation", hint: "Ton lien et tes invitations seulement.", icon: Lock },
  { value: "friends", title: "Mes amis", hint: "Tes amis la voient et la rejoignent seuls.", icon: Users },
  { value: "members", title: "Tous les membres", hint: "Une annonce visible de tous.", icon: Megaphone },
];

/** Story 43.22: where the run stands, or what is left to do. */
function OpennessStatus({ busy, dirty, publishing, choice }: { busy: boolean; dirty: boolean; publishing: boolean; choice: RunOpenness }) {
  if (busy) return <p className="text-xs text-muted-foreground" role="status">Enregistrement…</p>;
  if (dirty) {
    return (
      <p className="text-xs font-medium text-warning" role="status">
        {publishing ? "Écris ton annonce puis publie-la." : "Modifications non enregistrées."}
      </p>
    );
  }
  return (
    <p className="flex items-start gap-1.5 text-xs text-muted-foreground" role="status">
      <Check aria-hidden className="mt-px size-3.5 shrink-0 text-success" />
      {choice === "members"
        ? "Annonce en ligne."
        : choice === "friends"
          ? "Visible de tes amis dans « Parties de tes amis » : ils la rejoignent sans invitation tant qu'elle n'est pas lancée."
          : "Seuls ton lien et tes invitations font entrer quelqu'un."}
    </p>
  );
}

/**
 * The owner opens their draft run to all their friends, with an optional number of seats (story 43.14), or lists it
 * for every member with a short message and an optional date (story 43.17). Back on invitation, the run leaves the
 * lists; the link and the invitations by name keep working either way. Story 43.20: tiles instead of radio buttons,
 * a seats stepper, and a save button only when something changed.
 */
export function RunOpennessSetting({
  runId,
  openness,
  seatsWanted,
  pitch = null,
  plannedFor = null,
}: {
  runId: string;
  openness: RunOpenness;
  seatsWanted: number | null;
  pitch?: string | null;
  plannedFor?: string | null;
}) {
  const queryClient = useQueryClient();
  const initialSeats = seatsWanted === null ? "" : String(seatsWanted);
  const initialMessage = pitch ?? "";
  const initialDate = toLocalInput(plannedFor);
  const [choice, setChoice] = useState<RunOpenness>(openness);
  const [seats, setSeats] = useState(initialSeats);
  const [message, setMessage] = useState(initialMessage);
  const [date, setDate] = useState(initialDate);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const publishing = choice === "members" && openness !== "members";
  const dirty =
    publishing ||
    (choice !== "invite" && seats !== initialSeats) ||
    (choice === "members" && (message !== initialMessage || date !== initialDate));

  async function save(next: RunOpenness) {
    const parsed = seats.trim() === "" ? null : Number.parseInt(seats, 10);
    if (next !== "invite" && parsed !== null && (Number.isNaN(parsed) || parsed < 1 || parsed > MAX_SEATS_WANTED)) {
      setError(`Entre 1 et ${MAX_SEATS_WANTED} places, ou vide pour ne pas limiter.`);
      return;
    }
    if (next === "members" && message.trim() === "") {
      setError("Une annonce a besoin d'un message.");
      return;
    }
    setBusy(true);
    const listing = next === "members" ? { pitch: message, plannedFor: date === "" ? null : new Date(date).toISOString() } : null;
    const result = await setRunOpenness(runId, next, next === "invite" ? null : parsed, listing);
    setBusy(false);
    if (!result.ok) {
      setError(result.message);
      return;
    }
    setError(null);
    await queryClient.invalidateQueries({ queryKey: ["personal-run", runId] });
  }

  function pick(next: RunOpenness) {
    if (next === choice) return;
    setChoice(next);
    setError(null);
    // A listing waits for its message; the other choices apply at once.
    if (next !== "members") void save(next);
  }

  const seatsNumber = seats.trim() === "" ? null : Number.parseInt(seats, 10);

  function step(delta: 1 | -1) {
    if (seatsNumber === null || Number.isNaN(seatsNumber)) {
      if (delta === 1) setSeats("1");
      return;
    }
    const next = seatsNumber + delta;
    // Below one seat means no limit at all.
    setSeats(next < 1 ? "" : String(Math.min(next, MAX_SEATS_WANTED)));
  }

  const stepButton =
    "inline-flex size-9 items-center justify-center rounded-lg border border-border text-foreground transition-colors hover:border-accent disabled:opacity-40";

  return (
    <div className="grid gap-3">
      <fieldset className="grid gap-2">
        <legend className="mb-2 text-sm font-semibold text-foreground">Qui peut rejoindre ?</legend>
        <div className="grid gap-2 sm:grid-cols-3">
          {OPENNESS_CHOICES.map(({ value, title, hint, icon: Icon }) => {
            const selected = choice === value;
            return (
              <label
                className={[
                  "relative flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition-colors focus-within:ring-2 focus-within:ring-accent/60",
                  selected ? "border-accent-text bg-accent/25" : "border-border hover:border-accent",
                  busy ? "cursor-wait opacity-70" : "",
                ].join(" ")}
                key={value}
              >
                <input
                  checked={selected}
                  className="sr-only"
                  disabled={busy}
                  name={`openness-${runId}`}
                  onChange={() => pick(value)}
                  type="radio"
                  value={value}
                />
                <Icon aria-hidden className={`mt-0.5 size-4 shrink-0 ${selected ? "text-accent-text" : "text-muted-foreground"}`} />
                <span className="grid gap-0.5 pr-4">
                  <span className="text-sm font-semibold text-foreground">{title}</span>
                  <span className="text-xs text-muted-foreground">{hint}</span>
                </span>
                {selected ? <Check aria-hidden className="absolute right-2 top-2 size-3.5 text-accent-text" /> : null}
              </label>
            );
          })}
        </div>
      </fieldset>

      {choice !== "invite" ? (
        <form
          className="grid gap-4 rounded-lg border border-border bg-background/40 p-3"
          onSubmit={(e) => {
            e.preventDefault();
            void save(choice);
          }}
        >
          {choice === "members" ? (
            <div className="grid gap-1">
              <label className="flex justify-between gap-2 text-xs font-medium text-muted-foreground" htmlFor={`pitch-${runId}`}>
                Ton annonce
                <span className="tabular-nums">
                  {message.length} / {MAX_PITCH_LENGTH}
                </span>
              </label>
              <textarea
                className="min-h-20 rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground"
                id={`pitch-${runId}`}
                maxLength={MAX_PITCH_LENGTH}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="On cherche deux joueurs pour un async tranquille, rythme libre."
                value={message}
              />
              <p className="text-xs text-muted-foreground">
                Visible de tous dans{" "}
                <Link className="font-medium text-accent-text hover:text-accent-text-hover" href="/communaute/parties">
                  Parties qui cherchent des joueurs
                </Link>
                , avec les jeux choisis. Elle expire après 14 jours sans arrivée.
              </p>
            </div>
          ) : null}

          {/* Story 43.22: the date and the seats side by side. */}
          <div className="grid gap-4 sm:grid-cols-2">
            {choice === "members" ? (
              <div className="grid content-start gap-1">
                <label className="text-xs font-medium text-muted-foreground" htmlFor={`planned-${runId}`}>
                  Date prévue (facultatif)
                </label>
                <input
                  className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                  id={`planned-${runId}`}
                  onChange={(e) => setDate(e.target.value)}
                  type="datetime-local"
                  value={date}
                />
              </div>
            ) : null}
            <div className="grid content-start gap-1">
              <label className="text-xs font-medium text-muted-foreground" htmlFor={`seats-${runId}`}>
                {choice === "members" ? "Places voulues" : "Places pour tes amis"}
              </label>
              <div className="flex items-center gap-1.5">
                <button aria-label="Une place de moins" className={stepButton} disabled={busy || seatsNumber === null} onClick={() => step(-1)} type="button">
                  <Minus aria-hidden className="size-4" />
                </button>
                <input
                  className="min-h-9 w-24 rounded-lg border border-border bg-background px-2 text-center text-sm text-foreground placeholder:text-muted-foreground"
                  id={`seats-${runId}`}
                  inputMode="numeric"
                  max={MAX_SEATS_WANTED}
                  min={1}
                  onChange={(e) => setSeats(e.target.value)}
                  placeholder="Illimité"
                  type="number"
                  value={seats}
                />
                <button
                  aria-label="Une place de plus"
                  className={stepButton}
                  disabled={busy || (seatsNumber !== null && seatsNumber >= MAX_SEATS_WANTED)}
                  onClick={() => step(1)}
                  type="button"
                >
                  <Plus aria-hidden className="size-4" />
                </button>
              </div>
            </div>
          </div>

          {/* Story 43.22: where things stand and the save button on one row. */}
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
            <OpennessStatus busy={busy} choice={choice} dirty={dirty} publishing={publishing} />
            {dirty ? (
              <button className={buttonVariants({ variant: "primary" })} disabled={busy} type="submit">
                {publishing ? <Megaphone aria-hidden className="size-4" /> : null}
                {publishing ? "Publier l'annonce" : "Enregistrer"}
              </button>
            ) : null}
          </div>
        </form>
      ) : (
        <OpennessStatus busy={busy} choice={choice} dirty={false} publishing={false} />
      )}
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
