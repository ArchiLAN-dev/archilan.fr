"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { fetchCommunityStats } from "./community-api";
import { SESSION_STALE_TIME } from "@/lib/query-client";

export function CommunityStatsWidget() {
  const { data } = useQuery({
    queryKey: ["community-stats"],
    queryFn: fetchCommunityStats,
    staleTime: SESSION_STALE_TIME,
  });

  const containerRef = useRef<HTMLDivElement>(null);
  const [triggered, setTriggered] = useState(false);

  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setTriggered(true);
          observer.disconnect();
        }
      },
      { threshold: 0.3 },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  const stats = data ?? null;

  return (
    <section aria-labelledby="community-stats-heading" className="border-t border-border pt-12">
      <div className="mb-8">
        <p className="mb-2 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text text-on-canvas">
          La communauté en chiffres
        </p>
        <h2
          className="font-heading text-3xl font-bold text-foreground text-on-canvas"
          id="community-stats-heading"
        >
          Nos stats globales
        </h2>
      </div>

      <div ref={containerRef} className="grid gap-4 sm:grid-cols-3">
        <StatCounter
          label="runs terminées"
          target={stats?.totalFinishedSessions ?? null}
          triggered={triggered}
        />
        <StatCounter
          label="checks complétés"
          target={stats?.totalChecksDone ?? null}
          triggered={triggered}
        />
        <StatCounter
          label="objectifs atteints"
          target={stats?.totalGoalsReached ?? null}
          triggered={triggered}
        />
      </div>

      <div className="mt-6 flex justify-center">
        <Link
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border bg-surface px-5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href="/classements"
        >
          Voir le classement communautaire →
        </Link>
      </div>
    </section>
  );
}

function StatCounter({
  label,
  target,
  triggered,
}: {
  label: string;
  target: number | null;
  triggered: boolean;
}) {
  const count = useCountUp(target ?? 0, triggered && target !== null);
  const displayValue = target === null ? "-" : formatNumber(count);

  return (
    <div className="rounded-lg border border-border bg-surface p-6 text-center">
      <p className="font-heading text-4xl font-bold text-foreground">{displayValue}</p>
      <p className="mt-2 text-sm text-muted-foreground">{label}</p>
    </div>
  );
}

function useCountUp(target: number, enabled: boolean): number {
  const [count, setCount] = useState(0);

  useEffect(() => {
    if (!enabled || target <= 0) return;

    const duration = 1500;
    const startTime = Date.now();

    const tick = () => {
      const elapsed = Date.now() - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      setCount(Math.round(eased * target));
      if (progress < 1) {
        requestAnimationFrame(tick);
      }
    };

    const raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [target, enabled]);

  return count;
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("fr-FR").format(value);
}
