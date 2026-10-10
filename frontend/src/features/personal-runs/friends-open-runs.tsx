"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { DoorOpen, LogIn } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { FriendIdentity } from "@/features/community/friend-identity";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchFriendsOpenRuns,
  FRIENDS_OPEN_RUNS_KEY,
  joinOpenRun,
  MAX_SEATS_WANTED,
  setRunOpenness,
  type FriendsOpenRun,
} from "./friends-open-runs-api";
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
    await queryClient.invalidateQueries({ queryKey: FRIENDS_OPEN_RUNS_KEY });
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

/**
 * The owner opens their draft run to all their friends, with an optional number of seats (story 43.14). Back
 * on invitation, the run leaves the friends' lists; the link and the invitations by name keep working either way.
 */
export function RunOpennessSetting({
  runId,
  openness,
  seatsWanted,
}: {
  runId: string;
  openness: RunOpenness;
  seatsWanted: number | null;
}) {
  const queryClient = useQueryClient();
  const [seats, setSeats] = useState(seatsWanted === null ? "" : String(seatsWanted));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function save(next: RunOpenness, nextSeats: string) {
    const parsed = nextSeats.trim() === "" ? null : Number.parseInt(nextSeats, 10);
    if (parsed !== null && (Number.isNaN(parsed) || parsed < 1 || parsed > MAX_SEATS_WANTED)) {
      setMessage(`Entre 1 et ${MAX_SEATS_WANTED} places, ou vide pour ne pas limiter.`);
      return;
    }
    setBusy(true);
    const result = await setRunOpenness(runId, next, next === "friends" ? parsed : null);
    setBusy(false);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    setMessage(null);
    await queryClient.invalidateQueries({ queryKey: ["personal-run", runId] });
  }

  const choice = (value: RunOpenness, label: string) => (
    <label className="inline-flex items-center gap-1.5 text-sm text-foreground">
      <input checked={openness === value} disabled={busy} name={`openness-${runId}`} onChange={() => void save(value, seats)} type="radio" value={value} />
      {label}
    </label>
  );

  return (
    <fieldset className="grid gap-2 rounded-lg border border-border px-3 py-2">
      <legend className="inline-flex items-center gap-1.5 px-1 text-xs font-semibold text-muted-foreground">
        <DoorOpen aria-hidden className="size-3.5" />
        Ouverture
      </legend>
      <div className="flex flex-wrap items-center gap-4">
        {choice("invite", "Sur invitation")}
        {choice("friends", "Tous mes amis")}
      </div>
      {openness === "friends" ? (
        <form
          className="flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            void save("friends", seats);
          }}
        >
          <label className="text-xs text-muted-foreground" htmlFor={`seats-${runId}`}>
            Places pour tes amis
          </label>
          <input
            className="min-h-8 w-20 rounded border border-border bg-background px-2 text-sm text-foreground"
            id={`seats-${runId}`}
            inputMode="numeric"
            max={MAX_SEATS_WANTED}
            min={1}
            onChange={(e) => setSeats(e.target.value)}
            placeholder="Illimité"
            type="number"
            value={seats}
          />
          <button className="rounded border border-border px-2 py-1 text-xs font-semibold text-foreground hover:border-accent disabled:opacity-50" disabled={busy} type="submit">
            Enregistrer
          </button>
        </form>
      ) : null}
      <p className="text-xs text-muted-foreground">
        {openness === "friends"
          ? "Tes amis voient la partie dans « Parties de tes amis » et la rejoignent sans invitation, tant qu'elle n'est pas lancée."
          : "Seuls le lien et tes invitations par nom font entrer quelqu'un."}
      </p>
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
    </fieldset>
  );
}
