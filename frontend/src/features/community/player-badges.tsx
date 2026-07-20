import { BadgeCheck, ShieldCheck } from "lucide-react";

type Props = {
  level: number;
  isMember: boolean;
  isAdmin: boolean;
  playing: boolean;
  className?: string;
};

/**
 * Inline status badges for a player: level, Admin, Adhérent (live membership), and En jeu (live
 * presence). Mirrors the player-profile header pills so participant lists stay coherent with the
 * profile (story 30.37 / issue #261). `isMember` is the live-membership badge, not the stale role.
 */
export function PlayerBadges({ level, isMember, isAdmin, playing, className }: Props) {
  return (
    <span className={`flex flex-wrap items-center gap-1.5 ${className ?? ""}`}>
      <span className="inline-flex items-center rounded-full border border-accent/40 bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent-text">
        Niv. {level}
      </span>
      {isAdmin ? (
        <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-400">
          <ShieldCheck aria-hidden className="size-3" /> Admin
        </span>
      ) : null}
      {isMember ? (
        <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-400">
          <BadgeCheck aria-hidden className="size-3" /> Adhérent
        </span>
      ) : null}
      {playing ? (
        <span
          className="inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-400"
          title="En jeu"
        >
          <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-emerald-400" /> En jeu
        </span>
      ) : null}
    </span>
  );
}
