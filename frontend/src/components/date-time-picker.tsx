"use client";

import { CalendarDays, ChevronLeft, ChevronRight, Clock } from "lucide-react";
import { useId, useState } from "react";
import { Popover } from "radix-ui";

const DAY_LABELS = ["Lu", "Ma", "Me", "Je", "Ve", "Sa", "Di"];

function getCalendarDays(year: number, month: number): Array<Date | null> {
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const leadingBlanks = (firstDay.getDay() + 6) % 7; // 0 = Mon

  const days: Array<Date | null> = Array<null>(leadingBlanks).fill(null);
  for (let d = 1; d <= lastDay.getDate(); d++) {
    days.push(new Date(year, month, d));
  }
  while (days.length % 7 !== 0) days.push(null);
  return days;
}

function sameDay(a: Date, b: Date) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function initTime(iso?: string) {
  if (!iso) return "12:00";
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function toHiddenValue(day: Date | null, time: string) {
  if (!day) return "";
  const [h, m] = time.split(":").map(Number);
  const d = new Date(day);
  d.setHours(h ?? 0, m ?? 0, 0, 0);
  return d.toISOString();
}

function toDisplayText(day: Date | null, time: string) {
  if (!day) return null;
  const [h, m] = time.split(":").map(Number);
  const d = new Date(day);
  d.setHours(h ?? 0, m ?? 0, 0, 0);
  return d.toLocaleString("fr-FR", {
    weekday: "short",
    day: "numeric",
    month: "long",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function DateTimePicker({
  defaultValue,
  error,
  label,
  name,
}: {
  defaultValue?: string;
  error?: string;
  label: string;
  name: string;
}) {
  const errorId = useId();
  const init = defaultValue ? new Date(defaultValue) : null;

  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<Date | null>(init);
  const [viewYear, setViewYear] = useState(
    init?.getFullYear() ?? new Date().getFullYear(),
  );
  const [viewMonth, setViewMonth] = useState(
    init?.getMonth() ?? new Date().getMonth(),
  );
  const [time, setTime] = useState(() => initTime(defaultValue));

  const hiddenValue = toHiddenValue(selected, time);
  const displayText = toDisplayText(selected, time);

  const today = new Date();
  const rawMonthLabel = new Intl.DateTimeFormat("fr-FR", {
    month: "long",
    year: "numeric",
  }).format(new Date(viewYear, viewMonth));
  const monthLabel =
    rawMonthLabel.charAt(0).toUpperCase() + rawMonthLabel.slice(1);

  function prevMonth() {
    if (viewMonth === 0) {
      setViewMonth(11);
      setViewYear((y) => y - 1);
    } else {
      setViewMonth((m) => m - 1);
    }
  }

  function nextMonth() {
    if (viewMonth === 11) {
      setViewMonth(0);
      setViewYear((y) => y + 1);
    } else {
      setViewMonth((m) => m + 1);
    }
  }

  const calendarDays = getCalendarDays(viewYear, viewMonth);

  return (
    <div className="grid gap-2 text-sm font-semibold text-foreground">
      <span>{label}</span>

      <Popover.Root onOpenChange={setOpen} open={open}>
        <Popover.Trigger asChild>
          <button
            aria-describedby={error ? errorId : undefined}
            className={[
              "flex min-h-11 w-full items-center gap-2.5 rounded border px-3 text-left text-sm font-normal transition-colors outline-none",
              "bg-background",
              error
                ? "border-danger"
                : open
                  ? "border-accent"
                  : "border-border hover:border-accent/60",
            ].join(" ")}
            type="button"
          >
            <CalendarDays aria-hidden className="size-4 shrink-0 text-muted-foreground" />
            <span className={`flex-1 truncate ${displayText ? "text-foreground" : "text-muted-foreground"}`}>
              {displayText ?? "Choisir une date…"}
            </span>
          </button>
        </Popover.Trigger>

        <Popover.Portal>
          <Popover.Content
            align="start"
            className="z-50 w-72 rounded-xl border border-border bg-surface shadow-xl outline-none animate-in fade-in-0 zoom-in-95"
            sideOffset={4}
          >
            {/* Month navigation */}
            <div className="flex items-center justify-between px-4 pt-4 pb-2">
              <button
                aria-label="Mois précédent"
                className="flex size-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                onClick={prevMonth}
                type="button"
              >
                <ChevronLeft className="size-4" />
              </button>
              <span className="text-sm font-semibold text-foreground">
                {monthLabel}
              </span>
              <button
                aria-label="Mois suivant"
                className="flex size-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                onClick={nextMonth}
                type="button"
              >
                <ChevronRight className="size-4" />
              </button>
            </div>

            {/* Weekday headers */}
            <div className="grid grid-cols-7 px-3 pb-1">
              {DAY_LABELS.map((d) => (
                <div
                  className="flex h-8 items-center justify-center text-xs font-medium text-muted-foreground"
                  key={d}
                >
                  {d}
                </div>
              ))}
            </div>

            {/* Days grid */}
            <div className="grid grid-cols-7 gap-y-0.5 px-3 pb-3">
              {calendarDays.map((day, i) => {
                if (!day) return <div key={`blank-${i}`} />;

                const isSelected = selected !== null && sameDay(day, selected);
                const isToday = sameDay(day, today);

                return (
                  <button
                    className={[
                      "flex size-9 items-center justify-center rounded-lg text-sm transition-colors",
                      isSelected
                        ? "bg-accent font-semibold text-white"
                        : isToday
                          ? "border border-accent/40 font-semibold text-accent hover:bg-accent/10"
                          : "text-foreground hover:bg-accent/10",
                    ].join(" ")}
                    key={day.toISOString()}
                    onClick={() => setSelected(day)}
                    type="button"
                  >
                    {day.getDate()}
                  </button>
                );
              })}
            </div>

            {/* Time row */}
            <div className="flex items-center gap-3 border-t border-border px-4 py-3">
              <Clock aria-hidden className="size-4 shrink-0 text-muted-foreground" />
              <span className="text-sm font-normal text-muted-foreground">
                Heure
              </span>
              <input
                className="ml-auto min-h-8 rounded border border-border bg-background px-2 text-sm text-foreground outline-none focus:border-accent"
                onChange={(e) => setTime(e.target.value)}
                type="time"
                value={time}
              />
            </div>

            {/* Confirm */}
            <div className="px-4 pb-4">
              <button
                className="inline-flex min-h-9 w-full items-center justify-center rounded bg-accent text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                disabled={!selected}
                onClick={() => setOpen(false)}
                type="button"
              >
                Confirmer
              </button>
            </div>
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>

      <input name={name} type="hidden" value={hiddenValue} />
      {error ? (
        <span className="text-xs text-danger" id={errorId}>
          {error}
        </span>
      ) : null}
    </div>
  );
}
