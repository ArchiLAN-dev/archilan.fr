"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowRight, CalendarClock, Flag, LogIn, Megaphone, Settings2, Users } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";

import { Dialog } from "@/components/ui/dialog";
import { useAuth } from "@/features/auth/auth-context";
import { REPORT_PROBLEMS } from "@/features/community/community-report-api";
import { MemberAvatar } from "@/features/community/member-avatar";
import { TitledName } from "@/features/community/titled-name";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { FRIENDS_OPEN_RUNS_KEY, joinOpenRun } from "./friends-open-runs-api";
import { fetchRunListings, reportRunListing, RUN_LISTINGS_KEY, type ListingReportResult, type RunListing } from "./run-listings-api";

const DATE = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

/** « Prévue le samedi 2 novembre à 20:00 », or null without a date. */
export function plannedLabel(plannedFor: string | null): string | null {
  if (plannedFor === null) return null;
  const date = new Date(plannedFor);
  return Number.isNaN(date.getTime()) ? null : `Prévue le ${DATE.format(date)}`;
}

/** « Alice et Bob y sont déjà », the viewer's friends already in the run. */
export function friendsInLabel(friends: RunListing["friendsIn"]): string | null {
  const names = friends.map((friend) => friend.displayName ?? friend.slug);
  if (names.length === 0) return null;
  if (names.length === 1) return `${names[0]} y est déjà`;
  return `${names.slice(0, -1).join(", ")} et ${names[names.length - 1]} y sont déjà`;
}

/**
 * « Parties qui cherchent des joueurs » (story 43.17): the runs listed for every member, the latest first, the
 * viewer's friends already in them put forward. Story 43.24: visitors see them too, and sign in to join.
 */
export function RunListingsBoard() {
  const { loading } = useAuth();
  const { data, isLoading } = useRunListings();

  if (loading) return null;
  if (isLoading) return <p className="text-sm text-muted-foreground">Chargement des annonces…</p>;
  if (!data) return <p className="text-sm text-muted-foreground">Impossible de charger les annonces.</p>;
  if (data.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Aucune partie ne cherche de joueurs pour l&apos;instant. Publie la tienne depuis{" "}
        <Link className="font-medium text-accent-text hover:underline" href="/compte/parties">
          Mes parties
        </Link>{" "}
        : réglage « Ouverture », « Tous les membres ».
      </p>
    );
  }

  return (
    <ul className="grid gap-4 md:grid-cols-2" role="list">
      {data.map((listing) => (
        <ListingCard key={listing.runId} listing={listing} />
      ))}
    </ul>
  );
}

const MAX_GAMES = 5;
/** Up to this many seats wanted, they show as dots. */
const MAX_SEAT_DOTS = 8;

/** Story 43.25: what is left to take, rather than what is taken. */
export function seatsLeftLabel({ seatsWanted, joined }: Pick<RunListing, "seatsWanted" | "joined">): string {
  if (seatsWanted === null) {
    return joined === 0 ? "Places illimitées" : `${joined} inscrit${joined > 1 ? "s" : ""} · places illimitées`;
  }
  const left = Math.max(seatsWanted - joined, 0);
  return `${left} place${left > 1 ? "s" : ""} libre${left > 1 ? "s" : ""} sur ${seatsWanted}`;
}

function SeatsChip({ listing }: { listing: RunListing }) {
  const { seatsWanted, joined } = listing;
  const dots = seatsWanted !== null && seatsWanted <= MAX_SEAT_DOTS;
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/40 px-2.5 py-1 text-foreground">
      {dots ? (
        <span aria-hidden className="flex gap-0.5">
          {Array.from({ length: seatsWanted }, (_, seat) => (
            <span className={`size-2 rounded-full ${seat < joined ? "bg-accent" : "border border-accent-text/60"}`} key={seat} />
          ))}
        </span>
      ) : (
        <Users aria-hidden className="size-3.5 text-accent-text" />
      )}
      {seatsLeftLabel(listing)}
    </span>
  );
}

function ListingCard({ listing }: { listing: RunListing }) {
  const router = useRouter();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [reporting, setReporting] = useState(false);
  const planned = plannedLabel(listing.plannedFor);
  const friendsIn = friendsInLabel(listing.friendsIn);
  const mine = listing.isMine === true;

  async function join() {
    setBusy(true);
    setMessage(null);
    const result = await joinOpenRun(listing.runId);
    setBusy(false);
    // Story 43.19: a listed run is in « Parties de tes amis » as well.
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: RUN_LISTINGS_KEY }),
      queryClient.invalidateQueries({ queryKey: FRIENDS_OPEN_RUNS_KEY }),
    ]);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    router.push(`/runs/${listing.runId}`);
  }

  const name = listing.owner.displayName ?? listing.owner.slug;
  const shownGames = listing.games.slice(0, MAX_GAMES);

  // Story 43.25: the title leads, then who hosts; the facts (date, seats, games) as chips; the action at the bottom.
  return (
    <li
      className={`flex flex-col gap-3 rounded-xl border bg-surface p-4 transition-colors ${mine ? "border-accent" : "border-border hover:border-accent/60"}`}
    >
      <div className="flex items-start gap-3">
        <div className="grid min-w-0 flex-1 gap-1.5">
          <h3 className="font-heading text-lg font-semibold leading-tight text-foreground">{listing.title}</h3>
          {/* Room around the avatar: a cosmetic frame spills past its box. */}
          <Link className="mt-1 inline-flex w-fit items-center gap-2.5 py-1.5 pl-1 text-xs text-muted-foreground hover:text-foreground" href={`/joueurs/${listing.owner.slug}`}>
            <MemberAvatar
              avatarAnimatedUrl={listing.owner.avatarAnimatedUrl}
              avatarUrl={listing.owner.avatarUrl}
              frame={listing.owner.avatarFrame}
              framing={listing.owner.avatarFraming}
              name={name}
              size={24}
            />
            <span>
              par{" "}
              <TitledName style={listing.owner.nameStyle} variant="card">
                {name}
              </TitledName>
            </span>
          </Link>
        </div>
        {/* Story 43.23: the viewer's own listing, as the others see it. */}
        {mine ? (
          <span className="shrink-0 rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-white">Ta partie</span>
        ) : listing.isOwnerFriend ? (
          <span className="shrink-0 rounded-full bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent-text">Ami</span>
        ) : null}
      </div>

      <p className="line-clamp-4 whitespace-pre-line text-sm text-foreground">{listing.pitch}</p>

      <div className="flex flex-wrap gap-2 text-xs">
        {planned !== null ? (
          <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background/40 px-2.5 py-1 text-foreground">
            <CalendarClock aria-hidden className="size-3.5 text-accent-text" />
            {planned.replace(/^Prévue le /, "")}
          </span>
        ) : null}
        <SeatsChip listing={listing} />
      </div>

      {shownGames.length > 0 ? (
        <ul aria-label="Jeux" className="flex flex-wrap gap-1.5" role="list">
          {shownGames.map((game) => (
            <li className="rounded-md bg-accent/10 px-2 py-0.5 text-xs text-accent-text" key={game}>
              {game}
            </li>
          ))}
          {listing.games.length > MAX_GAMES ? (
            <li className="rounded-md px-1 py-0.5 text-xs text-muted-foreground">+{listing.games.length - MAX_GAMES}</li>
          ) : null}
        </ul>
      ) : null}

      {friendsIn !== null ? (
        <p className="inline-flex items-center gap-1.5 text-xs font-medium text-accent-text">
          <Users aria-hidden className="size-3.5" />
          {friendsIn}
        </p>
      ) : null}

      <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-border pt-3">
        {mine ? (
          <>
            <Link className={buttonVariants({ variant: "secondary" })} href={`/runs/${listing.runId}`}>
              <Settings2 aria-hidden className="size-4" />
              Gérer ma partie
            </Link>
            <span className="text-xs text-muted-foreground">Les autres membres la voient ainsi.</span>
          </>
        ) : user === null ? (
          // Story 43.24: a visitor signs in, then comes back to the listings.
          <Link className={buttonVariants({ variant: "primary" })} href="/connexion?returnTo=/communaute/parties">
            <LogIn aria-hidden className="size-4" />
            Se connecter pour rejoindre
          </Link>
        ) : (
          <>
            <button className={buttonVariants({ variant: "primary" })} disabled={busy} onClick={() => void join()} type="button">
              <LogIn aria-hidden className="size-4" />
              Rejoindre
            </button>
            <button
              className="ml-auto inline-flex min-h-9 items-center gap-1 px-1 text-xs text-muted-foreground hover:text-foreground"
              onClick={() => setReporting(true)}
              type="button"
            >
              <Flag aria-hidden className="size-3.5" />
              Signaler
            </button>
          </>
        )}
      </div>
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
      <Dialog description="La modération reçoit l'annonce telle qu'elle est." onOpenChange={setReporting} open={reporting} title="Signaler l'annonce">
        <ListingReportForm onDone={() => setReporting(false)} runId={listing.runId} />
      </Dialog>
    </li>
  );
}

const REPORT_ERRORS: Record<Exclude<ListingReportResult, "ok">, string> = {
  forbidden: "Tu ne peux pas signaler ta propre annonce.",
  invalid: "Choisis un contenu problématique.",
  not_found: "Cette annonce n'est plus en ligne.",
  error: "Le signalement n'a pas pu être envoyé. Réessaie.",
};

function ListingReportForm({ runId, onDone }: { runId: string; onDone: () => void }) {
  const [problem, setProblem] = useState<string>(REPORT_PROBLEMS[0].value);
  const [comment, setComment] = useState("");
  const [state, setState] = useState<"form" | "sending" | "sent">("form");
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setState("sending");
    const result = await reportRunListing(runId, problem, comment);
    if (result === "ok") {
      setState("sent");
      return;
    }
    setState("form");
    setError(REPORT_ERRORS[result]);
  }

  if (state === "sent") {
    return (
      <div className="grid gap-3 px-5 py-4">
        <p className="text-sm text-foreground">Merci, le signalement a bien été transmis à la modération.</p>
        <button className="ml-auto rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:bg-accent-hover" onClick={onDone} type="button">
          Fermer
        </button>
      </div>
    );
  }

  return (
    <div className="grid gap-3 px-5 py-4">
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Contenu problématique</span>
        <select className="min-h-9 rounded border border-border bg-background px-2 text-sm text-foreground" onChange={(e) => setProblem(e.target.value)} value={problem}>
          {REPORT_PROBLEMS.map((p) => (
            <option key={p.value} value={p.value}>
              {p.label}
            </option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Précisions (facultatif)</span>
        <textarea className="min-h-16 rounded border border-border bg-background px-2 py-1.5 text-sm text-foreground" maxLength={500} onChange={(e) => setComment(e.target.value)} value={comment} />
      </label>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <button
        className="inline-flex items-center justify-center gap-2 rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
        disabled={state === "sending"}
        onClick={() => void submit()}
        type="button"
      >
        <Flag aria-hidden className="size-4" />
        Envoyer le signalement
      </button>
    </div>
  );
}

/** Story 43.24: per viewer (a visitor included), so signing in does not show a visitor's list. */
export function runListingsKey(userId: string | null) {
  return [...RUN_LISTINGS_KEY, userId ?? "visitor"] as const;
}

function useRunListings() {
  const { user, loading } = useAuth();
  return useQuery({ queryKey: runListingsKey(user?.id ?? null), queryFn: fetchRunListings, enabled: !loading, staleTime: DEFAULT_STALE_TIME, retry: false });
}

/** Story 43.26: the listings the viewer could join (their own left out). */
export function joinableCount(listings: RunListing[]): number {
  return listings.filter((listing) => listing.isMine !== true).length;
}

/** Story 43.26: that count beside « Communauté » in the site header, visitors included. Nothing at zero. */
export function RunListingsBadge() {
  const { data } = useRunListings();
  const count = data ? joinableCount(data) : 0;
  if (count === 0) return null;
  const label = `${count} partie${count > 1 ? "s" : ""} cherche${count > 1 ? "nt" : ""} des joueurs`;
  return (
    <span className="ml-1.5 inline-flex items-center gap-1 rounded-full bg-success/15 px-2 py-0.5 text-xs font-semibold text-success" title={label}>
      <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-success" />
      {count}
      <span className="sr-only"> {label}</span>
    </span>
  );
}

const SPOTLIGHT_SIZE = 2;

/** Story 43.21: the latest listings, put forward on « Communauté », and the way to all of them. */
export function RunListingsSpotlight() {
  const { user, loading } = useAuth();
  const { data } = useRunListings();
  if (loading) return null;
  return <RunListingsSpotlightView listings={data ?? []} signedIn={user !== null} />;
}

function RunListingsSpotlightView({ listings, signedIn }: { listings: RunListing[]; signedIn: boolean }) {
  const count = listings.length;
  return (
    <section aria-labelledby="run-listings-spotlight" className="grid gap-3 rounded-xl border-2 border-accent bg-accent/10 p-4 shadow-[0_0_24px_-8px_var(--color-accent)]">
      <div className="flex flex-wrap items-center gap-3">
        <Megaphone aria-hidden className="size-5 text-accent-text" />
        <h2 className="font-heading text-lg font-semibold text-foreground" id="run-listings-spotlight">
          Parties qui cherchent des joueurs
        </h2>
        {count > 0 ? <span className="rounded-full bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent-text">{count}</span> : null}
        <Link className="ml-auto inline-flex items-center gap-1 text-sm font-medium text-accent-text hover:underline" href="/communaute/parties">
          {count > SPOTLIGHT_SIZE ? `Voir les ${count} annonces` : "Voir les annonces"}
          <ArrowRight aria-hidden className="size-4" />
        </Link>
      </div>
      {count === 0 ? (
        signedIn ? (
          <p className="text-sm text-muted-foreground">
            Aucune partie ne cherche de joueurs pour l&apos;instant. Publie la tienne depuis{" "}
            <Link className="font-medium text-accent-text hover:underline" href="/compte/parties">
              Mes parties
            </Link>{" "}
            : réglage « Ouverture », « Tous les membres ».
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">Aucune partie ne cherche de joueurs pour l&apos;instant.</p>
        )
      ) : (
        <ul className="grid gap-4 md:grid-cols-2" role="list">
          {listings.slice(0, SPOTLIGHT_SIZE).map((listing) => (
            <ListingCard key={listing.runId} listing={listing} />
          ))}
        </ul>
      )}
    </section>
  );
}
