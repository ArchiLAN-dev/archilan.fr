"use client";

import { useState } from "react";
import { Shovel } from "lucide-react";

import { PelleAmount, type PelleTier } from "./pelle-amount";
import styles from "./pelle-balance.module.css";

const TIER_EFFECT: Record<PelleTier, string | undefined> = {
  gold: styles.gold,
  event: styles.silver,
  bronze: styles.bronze,
};

type Playback = { seen: number; plays: number };

/** A gain plays the animation again, a loss does not. */
export function afterBalance(playback: Playback, amount: number): Playback {
  return { seen: amount, plays: amount > playback.seen ? playback.plays + 1 : playback.plays };
}

/**
 * Story 41.71: a main balance, whose pelle comes alive once on arrival, on hover and when the balance grows. A new key
 * remounts the mark, which is what replays a CSS animation. Lists keep the still {@see PelleAmount}: fifty lines
 * glinting at once would say nothing.
 */
export function PelleBalance({ amount, kind, className = "" }: { amount: number; kind: PelleTier; className?: string }) {
  const [playback, setPlayback] = useState<Playback>({ seen: amount, plays: 0 });
  if (amount !== playback.seen) setPlayback(afterBalance(playback, amount));

  return (
    <span className="inline-flex" onMouseEnter={() => setPlayback((p) => ({ ...p, plays: p.plays + 1 }))}>
      <PelleAmount
        amount={amount}
        className={className}
        kind={kind}
        mark={
          <span className={`${styles.mark} ${TIER_EFFECT[kind]}`} key={playback.plays}>
            <Shovel aria-hidden />
          </span>
        }
      />
    </span>
  );
}
