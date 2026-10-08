"use client";

import { useState, type ReactNode } from "react";
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import { GripVertical, Plus, X } from "lucide-react";

import { EVENTS_FACT, eventIdOfFact, eventScopedFact, isEventScopedFact } from "./admin-achievement-event-scope";
import {
  isRuleGroup,
  type AchievementFormOptions,
  type RuleCondition,
  type RuleGroup,
  type RuleGroupOp,
  type RuleNode,
  type RuleOperator,
} from "./admin-achievements-api";
import { FAMILY_STYLES, GROUP_STYLES } from "./achievement-rule-chips";
import {
  FAMILY_LABELS,
  GROUP_JOINERS,
  OPERATOR_WORDS,
  factsByFamily,
  familyOf,
  moveNode,
  parsePathKey,
  pathKey,
  ruleInFrench,
  type RulePath,
} from "./achievement-rules";

const GROUP_MODES: { op: RuleGroupOp; label: string }[] = [
  { op: "all", label: "toutes" },
  { op: "any", label: "au moins une" },
  { op: "none", label: "aucune" },
];

const SLOT_PREFIX = "slot:";

type Props = { rule: RuleGroup; options: AchievementFormOptions; onChange: (rule: RuleGroup) => void };

/**
 * Story 30.51: the rule as a tree. Each group shows its mode on a coloured rail and repeats its link (ET, OU, NI)
 * between its lines; a condition reads as a phrase; any condition or sub-group moves to another place by its handle
 * (mouse, finger or keyboard), dropped between two lines. « En clair » says the whole rule in French.
 */
export function RuleTreeEditor({ rule, options, onChange }: Props) {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor));
  const [dragging, setDragging] = useState(false);

  function onDragEnd(event: DragEndEvent): void {
    setDragging(false);
    const over = event.over?.id;
    if (typeof over !== "string" || !over.startsWith(SLOT_PREFIX)) return;
    const [group, index] = over.slice(SLOT_PREFIX.length).split("@");
    onChange(moveNode(rule, parsePathKey(String(event.active.id)), parsePathKey(group ?? ""), Number(index)));
  }

  return (
    <div className="grid gap-3">
      <DndContext onDragCancel={() => setDragging(false)} onDragEnd={onDragEnd} onDragStart={() => setDragging(true)} sensors={sensors}>
        <GroupEditor dragging={dragging} group={rule} onChange={onChange} options={options} path={[]} />
      </DndContext>
      <div className="grid gap-1 rounded-lg border border-border bg-background p-3 text-sm leading-6">
        <span className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">En clair</span>
        <p className="text-foreground">{ruleInFrench(rule, options)}</p>
      </div>
    </div>
  );
}

function newCondition(options: AchievementFormOptions): RuleCondition {
  return { fact: options.facts[0]?.key ?? "", operator: options.operators[0] ?? ">=", value: 1 };
}

function GroupEditor({
  group,
  path,
  options,
  dragging,
  onChange,
  onRemove,
}: {
  group: RuleGroup;
  path: RulePath;
  options: AchievementFormOptions;
  dragging: boolean;
  onChange: (group: RuleGroup) => void;
  onRemove?: () => void;
}) {
  const root = path.length === 0;
  const style = GROUP_STYLES[group.op];
  const setChild = (index: number, node: RuleNode) => onChange({ ...group, rules: group.rules.map((r, i) => (i === index ? node : r)) });
  const removeChild = (index: number) => onChange({ ...group, rules: group.rules.filter((_, i) => i !== index) });
  const add = (node: RuleNode) => onChange({ ...group, rules: [...group.rules, node] });
  const handle = root ? null : <DragHandle id={pathKey(path)} label="Déplacer ce groupe" />;

  return (
    <div className={`grid gap-1.5 border-l-[3px] pl-3 ${style.rail} ${root ? "" : "rounded-lg border border-border bg-surface-2/40 py-2.5 pr-2.5"}`}>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {handle}
        <span className="text-muted-foreground">{root ? "Débloqué si le membre remplit" : "Remplit"}</span>
        <div aria-label="Mode du groupe" className="inline-flex rounded-lg border border-border p-0.5 text-xs font-semibold" role="group">
          {GROUP_MODES.filter((mode) => options.groupOps.includes(mode.op)).map((mode) => (
            <button
              aria-pressed={group.op === mode.op}
              className={`min-h-7 rounded-md px-2.5 ${group.op === mode.op ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"}`}
              key={mode.op}
              onClick={() => onChange({ ...group, op: mode.op })}
              type="button"
            >
              {mode.label}
            </button>
          ))}
        </div>
        <span className="text-muted-foreground">de ces conditions</span>
        {onRemove ? (
          <button aria-label="Supprimer ce groupe" className="ml-auto inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:text-red-400" onClick={onRemove} type="button">
            <X aria-hidden className="size-4" />
          </button>
        ) : null}
      </div>

      {group.rules.length === 0 ? <p className="text-xs text-amber-400">Ce groupe est vide : ajoute une condition.</p> : null}

      {group.rules.map((node, index) => (
        // Index keys: rule nodes are the persisted JSON and carry no id; every edit replaces the node object.
        <div className="grid gap-1.5" key={index}>
          <DropSlot active={dragging} group={path} index={index} />
          {index > 0 ? (
            <span className={`-ml-[1.15rem] w-fit rounded px-1.5 text-[10px] font-extrabold ${style.badge}`}>{GROUP_JOINERS[group.op]}</span>
          ) : null}
          {isRuleGroup(node) ? (
            <GroupEditor dragging={dragging} group={node} onChange={(next) => setChild(index, next)} onRemove={() => removeChild(index)} options={options} path={[...path, index]} />
          ) : (
            <ConditionEditor condition={node} onChange={(next) => setChild(index, next)} onRemove={() => removeChild(index)} options={options} path={[...path, index]} />
          )}
        </div>
      ))}
      <DropSlot active={dragging} group={path} index={group.rules.length} />

      <div className="flex flex-wrap gap-4 pt-1 text-xs font-semibold">
        <button className={`inline-flex items-center gap-1 ${style.tag} hover:underline`} onClick={() => add(newCondition(options))} type="button">
          <Plus aria-hidden className="size-3.5" /> Condition
        </button>
        <button className={`inline-flex items-center gap-1 ${style.tag} hover:underline`} onClick={() => add({ op: options.groupOps[0] ?? "all", rules: [newCondition(options)] })} type="button">
          <Plus aria-hidden className="size-3.5" /> Sous-groupe
        </button>
      </div>
    </div>
  );
}

function DragHandle({ id, label }: { id: string; label: string }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id });
  return (
    <button
      {...attributes}
      {...listeners}
      aria-label={label}
      className={`inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg text-muted-foreground hover:text-foreground ${isDragging ? "opacity-40" : ""}`}
      ref={setNodeRef}
      type="button"
    >
      <GripVertical aria-hidden className="size-4" />
    </button>
  );
}

/** Where a dragged line can land: between two lines of a group, or at its end. */
function DropSlot({ group, index, active }: { group: RulePath; index: number; active: boolean }) {
  const { setNodeRef, isOver } = useDroppable({ id: `${SLOT_PREFIX}${pathKey(group)}@${index}` });
  return (
    <div
      aria-hidden
      className={`rounded-full transition-all ${active ? "h-2" : "h-0"} ${isOver ? "h-1.5 bg-accent-text shadow-[0_0_8px_rgba(149,128,245,0.8)]" : ""}`}
      ref={setNodeRef}
    />
  );
}

function ConditionEditor({
  condition,
  path,
  options,
  onChange,
  onRemove,
}: {
  condition: RuleCondition;
  path: RulePath;
  options: AchievementFormOptions;
  onChange: (condition: RuleCondition) => void;
  onRemove: () => void;
}) {
  const family = familyOf(condition.fact);
  const eventScoped = isEventScopedFact(condition.fact);
  const eventId = eventIdOfFact(condition.fact);
  const eventMissing = eventId !== null && !options.events.some((e) => e.id === eventId);
  const field = "min-h-9 rounded-lg border border-border bg-surface px-2 text-sm text-foreground outline-none focus:border-accent";

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-background px-2 py-2">
      <DragHandle id={pathKey(path)} label="Déplacer cette condition" />
      <select
        aria-label="Critère"
        className={`min-h-9 rounded-lg border px-2 text-sm font-semibold outline-none focus:border-accent ${FAMILY_STYLES[family].chip}`}
        onChange={(e) => onChange({ ...condition, fact: e.target.value })}
        value={eventScoped ? EVENTS_FACT : condition.fact}
      >
        {factsByFamily(options).map((group) => (
          <optgroup key={group.family} label={FAMILY_LABELS[group.family]}>
            {group.facts.map((f) => (
              <option key={f.key} value={f.key}>
                {f.label}
              </option>
            ))}
          </optgroup>
        ))}
      </select>
      {eventScoped ? (
        <select aria-label="Événement" className={field} onChange={(e) => onChange({ ...condition, fact: eventScopedFact(e.target.value) })} value={eventId ?? ""}>
          <option value="">Tous les événements</option>
          {options.events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.title}
            </option>
          ))}
          {eventMissing && eventId !== null ? <option value={eventId}>(événement supprimé)</option> : null}
        </select>
      ) : null}
      <select aria-label="Opérateur" className={field} onChange={(e) => onChange(withOperator(condition, asOperator(e.target.value, options)))} value={condition.operator}>
        {options.operators.map((op) => (
          <option key={op} value={op}>
            {OPERATOR_WORDS[op]}
          </option>
        ))}
      </select>
      <NumberInput label="Valeur" onChange={(value) => onChange({ ...condition, value })} value={condition.value} />
      {condition.operator === "between" ? (
        <>
          <span className="text-xs text-muted-foreground">et</span>
          <NumberInput label="Valeur supérieure" onChange={(value2) => onChange({ ...condition, value2 })} value={condition.value2 ?? condition.value} />
        </>
      ) : null}
      <button aria-label="Retirer cette condition" className="ml-auto inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:text-red-400" onClick={onRemove} type="button">
        <X aria-hidden className="size-4" />
      </button>
    </div>
  );
}

function NumberInput({ label, value, onChange }: { label: string; value: number; onChange: (value: number) => void }): ReactNode {
  return (
    <input
      aria-label={label}
      className="min-h-9 w-24 rounded-lg border border-border bg-surface px-2 text-center text-sm font-semibold text-foreground outline-none focus:border-accent"
      inputMode="numeric"
      onChange={(e) => {
        const parsed = Number.parseInt(e.target.value, 10);
        onChange(Number.isNaN(parsed) ? 0 : parsed);
      }}
      type="number"
      value={value}
    />
  );
}

function withOperator(condition: RuleCondition, operator: RuleOperator): RuleCondition {
  if (operator === "between") return { ...condition, operator, value2: condition.value2 ?? condition.value };
  return { fact: condition.fact, operator, value: condition.value };
}

function asOperator(value: string, options: AchievementFormOptions): RuleOperator {
  return options.operators.find((op) => op === value) ?? options.operators[0] ?? ">=";
}
