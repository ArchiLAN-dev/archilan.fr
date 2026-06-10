"use client";

import { Search } from "lucide-react";
import { useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { getAccountMembership } from "@/features/payments/membership-api";
import { fetchCurrentWeeklyRuns } from "./weekly-runs-api";
import type { CurrentWeeklyRun } from "./weekly-runs-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

// ── Game card ─────────────────────────────────────────────────────────────────

function WeeklyRunGameCard({ run }: { run: CurrentWeeklyRun }) {
  const slug = slugify(run.gameName);

  return (
    <Link
      className="group relative flex aspect-[3/4] flex-col overflow-hidden rounded-xl border border-border bg-surface transition-colors hover:border-accent"
      href={`/runs-hebdo/jeu/${slug}`}
    >
      {/* Cover image */}
      {run.coverImageUrl ? (
        <Image
          alt={run.gameName}
          className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
          fill
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
          src={run.coverImageUrl}
        />
      ) : (
        <div className="flex h-full w-full items-center justify-center bg-surface-2">
          <span className="select-none font-heading text-5xl font-bold text-muted-foreground/30">
            {run.gameName.slice(0, 2).toUpperCase()}
          </span>
        </div>
      )}

      {/* Gradient overlay */}
      <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent" />

      {/* Game name at the bottom */}
      <div className="absolute bottom-0 left-0 right-0 p-4">
        <p className="font-heading text-base font-bold leading-tight text-white drop-shadow">
          {run.gameName}
        </p>
      </div>
    </Link>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export function WeeklyRunsClientPage() {
  const { user, loading } = useAuth();

  const isAdmin = user?.roles.includes("ROLE_ADMIN") === true;

  // Always fetch membership for non-admin users — ROLE_MEMBER in the JWT can be
  // stale (cotisation expirée sans demotion). The API is authoritative.
  const { data: membership, isLoading: membershipLoading } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
    staleTime: DEFAULT_STALE_TIME,
    enabled: Boolean(user) && !isAdmin,
  });

  // Members (and admins) can participate; everyone else can browse but not join.
  const canParticipate = isAdmin || membership?.status === "active";

  // The list endpoint is public (optional auth) — everyone sees the week's runs.
  const { data: runs = [] } = useQuery({
    queryKey: ["weekly-runs", "current"],
    queryFn: fetchCurrentWeeklyRuns,
    staleTime: DEFAULT_STALE_TIME,
    refetchInterval: 60_000,
  });

  if (loading || (user && !isAdmin && membershipLoading)) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-border border-t-accent" />
      </div>
    );
  }

  const notice = canParticipate ? null : <MembershipNotice loggedIn={Boolean(user)} />;

  if (runs.length === 0) {
    return (
      <div className="flex flex-col gap-6">
        {notice}
        <div className="py-16 text-center">
          <p className="text-lg font-semibold text-foreground">
            Aucun run cette semaine - revenez lundi&nbsp;!
          </p>
          <p className="mt-2 text-sm text-muted-foreground">
            Les runs hebdomadaires démarrent automatiquement chaque lundi.
          </p>
        </div>
      </div>
    );
  }

  // Deduplicate by gameName — one card per game even if multiple templates exist
  const seen = new Set<string>();
  const games: CurrentWeeklyRun[] = [];
  for (const run of runs) {
    const slug = slugify(run.gameName);
    if (!seen.has(slug)) {
      seen.add(slug);
      games.push(run);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      {notice}
      <GameGrid games={games} />
    </div>
  );
}

// ── Membership notice (browse open to all, participation members-only) ──────────

export function MembershipNotice({ loggedIn }: { loggedIn: boolean }) {
  return (
    <div className="flex flex-col gap-3 rounded-lg border border-special/40 bg-special/5 p-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="font-semibold text-foreground">Participation réservée aux membres</p>
        <p className="mt-1 text-sm text-muted-foreground">
          Découvre les runs de la semaine ci-dessous.{" "}
          {loggedIn ? "Adhère à l'association" : "Connecte-toi et adhère"} pour rejoindre une partie.
        </p>
      </div>
      <Link
        className="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href="/adhesion"
      >
        Adhérer
      </Link>
    </div>
  );
}

// ── Game grid with search ─────────────────────────────────────────────────────

function GameGrid({ games }: { games: CurrentWeeklyRun[] }) {
  const [query, setQuery] = useState("");

  const filtered = query.trim() === ""
    ? games
    : games.filter((run) =>
        run.gameName.toLowerCase().includes(query.toLowerCase()),
      );

  return (
    <div className="flex flex-col gap-5">
      {/* Search */}
      <div className="relative">
        <Search
          aria-hidden
          className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          aria-label="Rechercher un jeu"
          className="w-full rounded-lg border border-border bg-surface py-2.5 pl-9 pr-4 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent-text focus:outline-none"
          onChange={(e) => { setQuery(e.target.value); }}
          placeholder="Rechercher un jeu…"
          type="search"
          value={query}
        />
      </div>

      {/* Grid */}
      {filtered.length === 0 ? (
        <p className="py-10 text-center text-sm text-muted-foreground">
          Aucun jeu ne correspond à &ldquo;{query}&rdquo;.
        </p>
      ) : (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {filtered.map((run) => (
            <WeeklyRunGameCard key={run.weeklyRunId} run={run} />
          ))}
        </div>
      )}
    </div>
  );
}
