"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Check, Circle, Library, Loader2, Lock, ShieldCheck, Trophy, Users } from "lucide-react";

import { Dialog, DialogBody } from "@/components/ui/dialog";
import { Markdown } from "@/components/markdown/markdown";
import { useAuth } from "@/features/auth/auth-context";
import type { Achievement, AchievementRarity } from "@/features/players/player-profile-api";
import { AchievementCard, formatDate, rarityLabel } from "./achievement-card";
import { fetchAchievementProgress, type ProgressCondition, type ProgressGroup, type ProgressNode } from "./achievement-progress-api";

const NUMBER = new Intl.NumberFormat("fr-FR");

const GROUP_HEADINGS: Record<ProgressGroup["op"], string> = {
  all: "Toutes ces conditions",
  any: "Au moins une de ces conditions",
  none: "Aucune de ces conditions",
};

const OPERATOR_PHRASES: Record<string, string> = { ">=": "au moins", ">": "plus de", "=": "exactement", "!=": "autre que", "<=": "au plus", "<": "moins de" };

/** « Items reçus d'autres joueurs » without its parenthesised detail. */
function shortLabel(label: string): string {
  return label.replace(/\s*\([^)]*\)\s*$/, "");
}

/** The target of a condition in words: « au moins 3 000 », « entre 500 et 2 000 ». */
export function conditionTarget(condition: Pick<ProgressCondition, "operator" | "value" | "value2">): string {
  if (condition.operator === "between") return `entre ${NUMBER.format(condition.value)} et ${NUMBER.format(condition.value2 ?? condition.value)}`;
  return `${OPERATOR_PHRASES[condition.operator] ?? condition.operator} ${NUMBER.format(condition.value)}`;
}

/** How far a « at least » condition is, 0 to 100; null where a bar means nothing (« exactement », « au plus »…). */
export function conditionPercent(condition: Pick<ProgressCondition, "operator" | "value" | "current" | "met">): number | null {
  if (condition.operator !== ">=" && condition.operator !== ">") return null;
  if (condition.met) return 100;
  const target = condition.operator === ">" ? condition.value + 1 : condition.value;
  return target <= 0 ? 0 : Math.min(99, Math.floor((condition.current / target) * 100));
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

function AchievementDetailsDialog({ achievement, rarity, slug, collectionName, onClose }: Props & { onClose: () => void }) {
  const { user } = useAuth();
  const own = user !== null && user.slug === slug;
  const { data, isLoading } = useQuery({
    queryKey: ["achievement-progress", achievement.key],
    queryFn: () => fetchAchievementProgress(achievement.key),
    enabled: own,
    staleTime: 60_000,
  });

  return (
    <Dialog onOpenChange={(next) => (next ? null : onClose())} open title={achievement.name}>
      <DialogBody>
        <div className="flex items-start gap-4">
          {achievement.customImageUrl ? (
            // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
            <img alt="" className="size-16 shrink-0 rounded-full object-cover" src={achievement.customImageUrl} />
          ) : (
            <span
              aria-hidden
              className={`flex size-16 shrink-0 items-center justify-center rounded-full ${achievement.unlocked ? "bg-accent/15 text-accent-text" : "bg-surface-2 text-muted-foreground"}`}
            >
              {achievement.unlocked ? <Trophy className="size-7" /> : <Lock className="size-7" />}
            </span>
          )}
          <div className="grid min-w-0 gap-1.5 text-sm">
            <div className="text-muted-foreground">
              <Markdown inline>{achievement.description}</Markdown>
            </div>
            {achievement.unlocked && achievement.unlockedAt ? (
              <p className="text-accent-text">
                Débloqué le <time dateTime={achievement.unlockedAt}>{formatDate(achievement.unlockedAt)}</time>
              </p>
            ) : (
              <p className="font-semibold text-foreground">À obtenir</p>
            )}
            {own && data?.byTeam ? (
              <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <ShieldCheck aria-hidden className="size-3.5" /> Attribué par l&apos;équipe
              </p>
            ) : null}
            <p className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
              {rarity ? (
                <span className="inline-flex items-center gap-1">
                  <Users aria-hidden className="size-3" /> {rarityLabel(rarity)}
                </span>
              ) : null}
              {collectionName ? (
                <span className="inline-flex items-center gap-1">
                  <Library aria-hidden className="size-3" /> Collection « {collectionName} »
                </span>
              ) : null}
            </p>
          </div>
        </div>

        {own && !achievement.unlocked ? (
          <section aria-label="Où tu en es" className="grid gap-2">
            <h3 className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Où tu en es</h3>
            {isLoading ? (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 aria-hidden className="size-4 animate-spin" /> Calcul de ta progression…
              </p>
            ) : data?.progress ? (
              <ProgressTree group={data.progress} root />
            ) : (
              <p className="text-sm text-muted-foreground">Ta progression n&apos;est pas disponible pour ce succès.</p>
            )}
          </section>
        ) : null}
      </DialogBody>
    </Dialog>
  );
}

/** The rule, a group at a time: its heading says how its conditions combine and whether it holds. */
export function ProgressTree({ group, root = false }: { group: ProgressGroup; root?: boolean }) {
  return (
    <div className={`grid gap-2 ${root ? "" : "rounded-lg border border-border bg-surface-2/40 p-3"}`}>
      {root && group.rules.length === 1 ? null : (
        <p className="flex items-center gap-2 text-xs font-semibold text-muted-foreground">
          {GROUP_HEADINGS[group.op]}
          {group.met ? <span className="rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">rempli</span> : null}
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
  return (
    <div className="grid gap-1.5 rounded-lg border border-border bg-background px-3 py-2.5">
      <div className="flex items-start gap-2 text-sm">
        {ok ? <Check aria-label="Rempli" className="mt-0.5 size-4 shrink-0 text-success" /> : <Circle aria-label="Pas encore" className="mt-0.5 size-4 shrink-0 text-muted-foreground" />}
        <span className="min-w-0 flex-1 text-foreground">
          {shortLabel(node.label)}
          <span className="text-muted-foreground"> : {negated ? `pas ${conditionTarget(node)}` : conditionTarget(node)}</span>
        </span>
        <span className="shrink-0 font-semibold tabular-nums text-foreground">{NUMBER.format(node.current)}</span>
      </div>
      {percent !== null ? (
        <div aria-label={`${shortLabel(node.label)} : ${percent} %`} aria-valuemax={100} aria-valuemin={0} aria-valuenow={percent} className="h-1.5 overflow-hidden rounded-full bg-surface-2" role="progressbar">
          <div className={`h-full rounded-full ${ok ? "bg-success" : "bg-accent"}`} style={{ width: `${percent}%` }} />
        </div>
      ) : null}
    </div>
  );
}
