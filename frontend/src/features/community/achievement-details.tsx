"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Calendar, Check, Library, Loader2, Lock, ShieldCheck, Trophy, Users } from "lucide-react";

import { Dialog, DialogBody } from "@/components/ui/dialog";
import { Markdown } from "@/components/markdown/markdown";
import { useAuth } from "@/features/auth/auth-context";
import type { Achievement, AchievementRarity } from "@/features/players/player-profile-api";
import { AchievementCard, formatDate, rarityLabel } from "./achievement-card";
import { conditionPhrase } from "./achievement-phrasing";
import { fetchAchievementProgress, type ProgressCondition, type ProgressGroup, type ProgressNode } from "./achievement-progress-api";

const NUMBER = new Intl.NumberFormat("fr-FR");

const GROUP_HEADINGS: Record<ProgressGroup["op"], string> = {
  all: "Toutes ces conditions",
  any: "Au moins une de ces conditions",
  none: "Aucune de ces conditions",
};

/** The rail and tag of a group, by how it combines its conditions (the colours of the admin rule editor). */
const GROUP_STYLES: Record<ProgressGroup["op"], { rail: string; tag: string }> = {
  all: { rail: "border-l-accent-text", tag: "bg-accent/15 text-accent-text" },
  any: { rail: "border-l-sky-400", tag: "bg-sky-400/15 text-sky-300" },
  none: { rail: "border-l-red-400", tag: "bg-red-400/15 text-red-300" },
};

/** « Items reçus d'autres joueurs » without its parenthesised detail. */
function shortLabel(label: string): string {
  return label.replace(/\s*\([^)]*\)\s*$/, "");
}

/** The value a « at least » condition needs, or null where counting up means nothing (« exactement », « au plus »…). */
function countTarget(condition: Pick<ProgressCondition, "operator" | "value">): number | null {
  if (condition.operator === ">=") return condition.value;
  if (condition.operator === ">") return condition.value + 1;
  return null;
}

/** How far a « at least » condition is, 0 to 100; null where a bar means nothing (« exactement », « au plus »…). */
export function conditionPercent(condition: Pick<ProgressCondition, "operator" | "value" | "current" | "met">): number | null {
  const target = countTarget(condition);
  if (target === null) return null;
  if (condition.met) return 100;
  return target <= 0 ? 0 : Math.min(99, Math.floor((condition.current / target) * 100));
}

/**
 * The whole rule as one percentage, for the bar at the top: a condition by its own bar (all or nothing when it has
 * none), « toutes » by their mean, « au moins une » by the best, « aucune » all or nothing. Never 100 until it holds.
 */
export function progressScore(node: ProgressNode, negated = false): number {
  if (node.type === "condition") {
    if (negated) return node.met ? 0 : 100;
    return conditionPercent(node) ?? (node.met ? 100 : 0);
  }
  if (node.op === "none") return node.met ? 100 : 0;
  const scores = node.rules.map((child) => progressScore(child));
  const score = scores.length === 0 ? 0 : node.op === "all" ? scores.reduce((sum, s) => sum + s, 0) / scores.length : Math.max(...scores);
  return node.met ? 100 : Math.min(99, Math.floor(score));
}

/** The conditions met, counted like the rule reads them: under « aucune », one is on track while it does not hold. */
export function conditionsMet(node: ProgressNode, negated = false): { met: number; total: number } {
  if (node.type === "condition") return { met: (negated ? !node.met : node.met) ? 1 : 0, total: 1 };
  return node.rules.reduce(
    (sum, child) => {
      const count = conditionsMet(child, node.op === "none");
      return { met: sum.met + count.met, total: sum.total + count.total };
    },
    { met: 0, total: 0 },
  );
}

type Props = { achievement: Achievement; rarity?: AchievementRarity; slug: string; collectionName?: string };

/**
 * Story 30.53: an achievement tile that opens its details. On their own profile, the member also sees where they stand
 * on each condition, asked from the API when the modal opens (building every metric is too heavy for the catalogue).
 * On someone else's profile, only « à obtenir »: the numbers tell an activity a profile only shows as totals.
 */
export function AchievementTile({ achievement, rarity, slug, collectionName }: Props) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <AchievementCard achievement={achievement} onOpen={() => setOpen(true)} rarity={rarity} />
      {open ? <AchievementDetailsDialog achievement={achievement} collectionName={collectionName} onClose={() => setOpen(false)} rarity={rarity} slug={slug} /> : null}
    </>
  );
}

function Chip({ icon: Icon, children, tone = "muted" }: { icon: typeof Users; children: React.ReactNode; tone?: "muted" | "accent" | "success" }) {
  const tones = { muted: "border-border bg-surface-2 text-muted-foreground", accent: "border-accent/40 bg-accent/10 text-accent-text", success: "border-success/40 bg-success/10 text-success" };
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ${tones[tone]}`}>
      <Icon aria-hidden className="size-3.5" />
      {children}
    </span>
  );
}

function AchievementDetailsDialog({ achievement, rarity, slug, collectionName, onClose }: Props & { onClose: () => void }) {
  const { user } = useAuth();
  const own = user !== null && user.slug === slug;
  const { data, isLoading } = useQuery({
    queryKey: ["achievement-progress", achievement.key],
    queryFn: () => fetchAchievementProgress(achievement.key),
    enabled: own,
    staleTime: 60_000,
  });
  const unlocked = achievement.unlocked;

  return (
    <Dialog onOpenChange={(next) => (next ? null : onClose())} open title={achievement.name}>
      <DialogBody>
        <div className={`flex flex-col items-center gap-3 rounded-xl border px-4 py-5 text-center sm:flex-row sm:items-start sm:text-left ${unlocked ? "border-accent/40 bg-accent/5" : "border-border bg-surface-2/50"}`}>
          {achievement.customImageUrl ? (
            // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
            <img
              alt=""
              className={`size-20 shrink-0 rounded-full object-cover ring-4 ${unlocked ? "ring-accent/50" : "opacity-60 ring-border grayscale"}`}
              src={achievement.customImageUrl}
            />
          ) : (
            <span aria-hidden className={`flex size-20 shrink-0 items-center justify-center rounded-full ring-4 ${unlocked ? "bg-accent/15 text-accent-text ring-accent/40" : "bg-surface text-muted-foreground ring-border"}`}>
              {unlocked ? <Trophy className="size-9" /> : <Lock className="size-9" />}
            </span>
          )}
          <div className="grid min-w-0 gap-2.5">
            <div className="text-sm leading-6 text-foreground/90">
              <Markdown inline>{achievement.description}</Markdown>
            </div>
            <div className="flex flex-wrap justify-center gap-1.5 sm:justify-start">
              {unlocked && achievement.unlockedAt ? (
                <Chip icon={Calendar} tone="accent">
                  <span>
                    Débloqué le <time dateTime={achievement.unlockedAt}>{formatDate(achievement.unlockedAt)}</time>
                  </span>
                </Chip>
              ) : (
                <Chip icon={Lock}>À obtenir</Chip>
              )}
              {own && data?.byTeam ? <Chip icon={ShieldCheck}>Attribué par l&apos;équipe</Chip> : null}
              {rarity ? <Chip icon={Users}>{rarityLabel(rarity)}</Chip> : null}
              {collectionName ? <Chip icon={Library}>Collection « {collectionName} »</Chip> : null}
            </div>
          </div>
        </div>

        {own && !unlocked ? (
          <section aria-label="Où tu en es" className="grid gap-3">
            {isLoading ? (
              <p className="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground">
                <Loader2 aria-hidden className="size-4 animate-spin" /> Calcul de ta progression…
              </p>
            ) : data?.progress ? (
              <>
                <OverallProgress group={data.progress} />
                <ProgressTree group={data.progress} root />
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Ta progression n&apos;est pas disponible pour ce succès.</p>
            )}
          </section>
        ) : null}
      </DialogBody>
    </Dialog>
  );
}

/** A bar that reads at a glance: a thick contrasted track, a coloured fill. */
function Bar({ percent, done, label, size = "md" }: { percent: number; done: boolean; label: string; size?: "md" | "lg" }) {
  return (
    <div
      aria-label={label}
      aria-valuemax={100}
      aria-valuemin={0}
      aria-valuenow={percent}
      className={`overflow-hidden rounded-full bg-border ${size === "lg" ? "h-3" : "h-2"}`}
      role="progressbar"
    >
      <div
        className={`h-full rounded-full transition-[width] duration-500 ${done ? "bg-success" : "bg-gradient-to-r from-accent to-accent-text shadow-[0_0_10px_rgba(149,128,245,0.55)]"}`}
        style={{ width: `${Math.max(percent, percent > 0 ? 3 : 0)}%` }}
      />
    </div>
  );
}

/** The whole rule at the top: how far, and how many conditions are met. */
function OverallProgress({ group }: { group: ProgressGroup }) {
  const score = progressScore(group);
  const { met, total } = conditionsMet(group);
  return (
    <div className="grid gap-2 rounded-xl border border-border bg-background p-4">
      <div className="flex items-end justify-between gap-3">
        <span className="grid gap-0.5">
          <span className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Où tu en es</span>
          <span className="text-sm text-foreground">
            {met} {met > 1 ? "conditions remplies" : "condition remplie"} sur {total}
          </span>
        </span>
        <span className="font-heading text-3xl font-bold tabular-nums text-accent-text">{score} %</span>
      </div>
      <Bar done={group.met} label={`Progression : ${score} %`} percent={score} size="lg" />
    </div>
  );
}

/** The rule, a group at a time: a coloured rail and tag say how its conditions combine, and whether it holds. */
export function ProgressTree({ group, root = false }: { group: ProgressGroup; root?: boolean }) {
  const style = GROUP_STYLES[group.op];
  const single = root && group.rules.length === 1;
  return (
    <div className={`grid gap-2 ${single ? "" : `border-l-[3px] pl-3 ${style.rail}`} ${root ? "" : "rounded-r-lg bg-surface-2/40 py-2.5 pr-2.5"}`}>
      {single ? null : (
        <p className="flex items-center gap-2 text-xs font-semibold">
          <span className={`rounded px-1.5 py-0.5 ${style.tag}`}>{GROUP_HEADINGS[group.op]}</span>
          {group.met ? (
            <span className="inline-flex items-center gap-1 text-success">
              <Check aria-hidden className="size-3.5" /> rempli
            </span>
          ) : null}
        </p>
      )}
      <ul className="grid gap-2" role="list">
        {group.rules.map((node, index) => (
          // Index keys: the rule's nodes carry no id and never reorder here.
          <li key={index}>
            <ProgressNodeView negated={group.op === "none"} node={node} />
          </li>
        ))}
      </ul>
    </div>
  );
}

function ProgressNodeView({ node, negated }: { node: ProgressNode; negated: boolean }) {
  if (node.type === "group") return <ProgressTree group={node} />;
  // Under « aucune », a condition is on track while it does not hold.
  const ok = negated ? !node.met : node.met;
  const percent = negated ? null : conditionPercent(node);
  const target = negated ? null : countTarget(node);
  const left = target !== null && !ok ? target - node.current : 0;
  return (
    <div className={`grid gap-2 rounded-lg border px-3 py-3 ${ok ? "border-success/40 bg-success/5" : "border-border bg-background"}`}>
      <div className="flex items-start gap-2.5">
        <span
          aria-label={ok ? "Rempli" : "Pas encore"}
          className={`mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full ${ok ? "bg-success text-background" : "border-2 border-border text-muted-foreground"}`}
          role="img"
        >
          {ok ? <Check aria-hidden className="size-3.5" strokeWidth={3} /> : null}
        </span>
        <span className="min-w-0 flex-1 text-sm font-medium leading-5 text-foreground">{conditionPhrase(node, negated)}</span>
        <span className="shrink-0 text-right tabular-nums">
          <span className={`text-base font-bold ${ok ? "text-success" : "text-foreground"}`}>{NUMBER.format(node.current)}</span>
          {target !== null ? <span className="text-sm text-muted-foreground"> / {NUMBER.format(target)}</span> : null}
        </span>
      </div>
      {percent !== null ? (
        <div className="grid gap-1">
          <Bar done={ok} label={`${shortLabel(node.label)} : ${percent} %`} percent={percent} />
          <span className="flex justify-between text-[11px] text-muted-foreground">
            <span>{ok ? "Objectif atteint" : `Encore ${NUMBER.format(left)}`}</span>
            <span className="tabular-nums">{percent} %</span>
          </span>
        </div>
      ) : null}
    </div>
  );
}
