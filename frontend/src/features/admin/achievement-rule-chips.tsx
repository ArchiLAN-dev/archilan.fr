import { CircleHelp, Flag, Gamepad2, Medal, Package, ScrollText, Users, type LucideIcon } from "lucide-react";

import { isEventScopedFact } from "./admin-achievement-event-scope";
import { isRuleGroup, type AchievementFormOptions, type RuleGroup, type RuleGroupOp, type RuleNode } from "./admin-achievements-api";
import { conditionValue, familyOf, GROUP_JOINERS, GROUP_TAGS, ruleDepth, shortFactLabel, type FactFamily } from "./achievement-rules";

/** Story 30.51: one colour and one icon per family of criterion, the same in the list and the editor. */
export const FAMILY_STYLES: Record<FactFamily, { chip: string; icon: LucideIcon }> = {
  parties: { chip: "border-violet-400/45 bg-violet-400/15 text-violet-300", icon: Gamepad2 },
  progression: { chip: "border-teal-400/45 bg-teal-400/12 text-teal-300", icon: Package },
  objectifs: { chip: "border-amber-400/45 bg-amber-400/12 text-amber-300", icon: Flag },
  quetes: { chip: "border-yellow-400/45 bg-yellow-400/12 text-yellow-300", icon: ScrollText },
  recaps: { chip: "border-orange-400/45 bg-orange-400/12 text-orange-300", icon: Medal },
  ensemble: { chip: "border-sky-400/45 bg-sky-400/12 text-sky-300", icon: Users },
  autres: { chip: "border-border bg-surface-2 text-muted-foreground", icon: CircleHelp },
};

/** The rail and the tag of a group, by mode. */
export const GROUP_STYLES: Record<RuleGroupOp, { rail: string; frame: string; tag: string; badge: string }> = {
  all: { rail: "border-l-violet-400", frame: "border-violet-400/60", tag: "text-violet-300", badge: "bg-violet-400 text-background" },
  any: { rail: "border-l-teal-400", frame: "border-teal-400/60", tag: "text-teal-300", badge: "bg-teal-400 text-background" },
  none: { rail: "border-l-red-400", frame: "border-red-400/60", tag: "text-red-300", badge: "bg-red-400 text-background" },
};

/** Beyond this many nested frames, a list row says « Règle à N niveaux » instead of spreading. */
const MAX_LIST_DEPTH = 3;

/** An achievement's rule as the list shows it: chips joined by ET / OU, a sub-group in a labelled frame. */
export function RuleChips({ rule, options }: { rule: RuleGroup; options: AchievementFormOptions }) {
  const depth = ruleDepth(rule);
  if (depth > MAX_LIST_DEPTH) {
    return <span className="text-xs text-muted-foreground">Règle à {depth} niveaux, voir le détail</span>;
  }
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <Nodes group={rule} options={options} />
    </span>
  );
}

function Nodes({ group, options }: { group: RuleGroup; options: AchievementFormOptions }) {
  return group.rules.map((node, index) => (
    // Index keys: rule nodes are the persisted JSON and carry no id.
    <span className="contents" key={index}>
      {index > 0 && group.op !== "none" ? <span className="text-[10px] font-bold text-muted-foreground">{GROUP_JOINERS[group.op]}</span> : null}
      <Node node={node} options={options} />
    </span>
  ));
}

function Node({ node, options }: { node: RuleNode; options: AchievementFormOptions }) {
  if (isRuleGroup(node)) {
    const style = GROUP_STYLES[node.op];
    return (
      <span className={`inline-flex flex-wrap items-center gap-1.5 rounded-lg border border-dashed px-1.5 py-1 ${style.frame}`}>
        <span className={`px-1 text-[10px] font-bold uppercase tracking-wide ${style.tag}`}>{GROUP_TAGS[node.op]}</span>
        <Nodes group={node} options={options} />
      </span>
    );
  }
  const family = familyOf(node.fact);
  const Icon = FAMILY_STYLES[family].icon;
  return (
    <span className={`inline-flex h-6 items-center gap-1.5 rounded-full border px-2 text-xs ${FAMILY_STYLES[family].chip}`}>
      <Icon aria-hidden className="size-3" />
      <span>{shortFactLabel(node.fact, options)}</span>
      {isEventScopedFact(node.fact) && node.operator === ">=" && node.value === 1 ? null : <strong className="font-semibold text-foreground">{conditionValue(node)}</strong>}
    </span>
  );
}
