import type { CheckEntry } from "./types";

export function StatPill({
  label,
  value,
  highlight = false,
}: {
  label: string;
  value: string;
  highlight?: boolean;
}) {
  return (
    <div
      className={`rounded border px-3 py-1.5 text-sm ${
        highlight
          ? "border-success/30 bg-success/10 text-success"
          : "border-border bg-surface text-foreground"
      }`}
    >
      <span className="text-xs text-muted-foreground">{label} </span>
      <span className="font-semibold">{value}</span>
    </div>
  );
}

export function CheckRow({
  check,
  currentSlot,
  variant,
}: {
  check: CheckEntry;
  currentSlot: number;
  variant: "reachable" | "unreachable";
}) {
  const isOwnItem = check.item?.slot === currentSlot;
  return (
    <li className="grid gap-0.5 px-4 py-2.5">
      <span className={`text-sm ${variant === "reachable" ? "text-foreground" : "text-muted-foreground"}`}>
        {check.name}
      </span>
      {check.item ? (
        <span className="flex flex-wrap items-baseline gap-1.5 text-xs text-muted-foreground/70">
          <span>→ {check.item.name}</span>
          {!isOwnItem ? (
            <span className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-accent-text">
              {check.item.slot_name}
            </span>
          ) : null}
        </span>
      ) : null}
    </li>
  );
}
