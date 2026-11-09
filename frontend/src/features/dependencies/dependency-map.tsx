"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Network } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { bestGeneration, fetchDependencyMap, type DependencyMap } from "./dependency-map-api";

const SPHERE_CHOICES = [1, 2, 3, 4, 5, 8];

/**
 * « Carte des dépendances » (story 52.7): before the launch, for each generated session of the event, how many early
 * progression items wait in the world of someone absent, by the declared windows. The staff compares, then launches
 * the one they keep (a new session from the same registrations is a new seed). Nothing is regenerated here.
 */
export function DependencyMapPanel({ eventId }: { eventId: string }) {
  const [spheres, setSpheres] = useState(3);
  const { data, isPending } = useQuery({
    queryKey: ["dependency-map", eventId, spheres],
    queryFn: () => fetchDependencyMap(eventId, spheres),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!isPending && (!data || data.generations.length === 0)) return null;

  return (
    <section aria-labelledby="dependency-map-title" className="grid gap-3 rounded border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="dependency-map-title">
          <Network aria-hidden className="size-4 text-accent-text" />
          Carte des dépendances
        </h2>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          Premières sphères
          <select className="min-h-8 rounded border border-border bg-surface-2 px-2 text-xs text-foreground" onChange={(e) => setSpheres(Number(e.target.value))} value={spheres}>
            {SPHERE_CHOICES.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </select>
        </label>
      </div>
      {data ? <DependencyMapView eventId={eventId} map={data} /> : <p className="text-xs text-muted-foreground">Lecture des spoilers…</p>}
    </section>
  );
}

export function DependencyMapView({ eventId, map }: { eventId: string; map: DependencyMap }) {
  if (!map.declared) {
    return <p className="text-sm text-muted-foreground">Aucun inscrit n&apos;a déclaré de créneaux de présence : aucune dépendance n&apos;est à risque.</p>;
  }
  const best = bestGeneration(map.generations);

  return (
    <>
      <p className="text-xs text-muted-foreground">
        Un objet de progression est à risque quand il est dans le monde d&apos;un joueur absent pendant une demi-journée où celui qui l&apos;attend est là, dans
        ses {map.sphereLimit} premières sphères. Le score compte ces objets : le plus bas est le meilleur.
      </p>
      <ul className="grid gap-3">
        {map.generations.map((generation) => (
          <li className="grid gap-2 rounded border border-border p-3" key={generation.sessionId}>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <Link className="font-mono text-xs text-accent-text hover:underline" href={`/admin/evenements/${eventId}/session/${generation.sessionId}`}>
                {generation.sessionId.slice(0, 8)}
              </Link>
              <span className="flex items-center gap-2 text-xs">
                <span className="text-muted-foreground">{generation.status}</span>
                {generation.playthrough === "read" ? (
                  <span className={`rounded px-1.5 py-0.5 font-semibold ${generation.score === 0 ? "bg-success/15 text-success" : "bg-accent-warm/15 text-accent-warm"}`}>
                    {generation.score} à risque
                  </span>
                ) : (
                  <span className="text-muted-foreground">spoiler sans playthrough</span>
                )}
                {best === generation.sessionId ? <span className="rounded bg-accent/15 px-1.5 py-0.5 font-semibold text-accent-text">Meilleure</span> : null}
              </span>
            </div>
            {generation.pairs.length > 0 ? (
              <ul className="grid gap-1 text-xs">
                {generation.pairs.map((pair) => (
                  <li key={`${pair.waiting}-${pair.holding}`}>
                    <span className="font-medium text-foreground">{pair.waiting}</span>
                    {" : "}
                    {pair.items.length} objet{pair.items.length > 1 ? "s" : ""} chez <span className="font-medium text-foreground">{pair.holding}</span>
                    <span className="text-muted-foreground">
                      {" "}
                      ({pair.items.join(", ")}), pas là : {pair.halfDays.join(", ")}
                    </span>
                  </li>
                ))}
              </ul>
            ) : null}
          </li>
        ))}
      </ul>
    </>
  );
}
