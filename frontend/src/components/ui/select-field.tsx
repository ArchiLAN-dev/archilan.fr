"use client";

import { Check, ChevronDown } from "lucide-react";
import { Select } from "radix-ui";

import { cn } from "@/lib/utils";

export type SelectFieldOption<T extends string> = { value: T; label: string };

/**
 * A dropdown drawn by the site, not the operating system (story 39.13): on Windows a native select opens a
 * grey, square list that ignores the theme. Radix Select keeps what a native one gives - arrow keys, typing to
 * jump, Enter, Escape, screen readers - and opens a list in the site's colours. Closed it reads "Cible  Tous ▾",
 * its name inside the control.
 */
export function SelectField<T extends string>({
  label,
  options,
  value,
  onChange,
  highlighted = false,
  className,
}: {
  label: string;
  options: SelectFieldOption<T>[];
  value: T;
  onChange: (value: T) => void;
  /** Draws attention to the control, e.g. a filter that currently narrows a list. */
  highlighted?: boolean;
  className?: string;
}) {
  const selected = options.find((option) => option.value === value);

  return (
    <Select.Root
      onValueChange={(next) => {
        const option = options.find((candidate) => candidate.value === next);
        if (option !== undefined) onChange(option.value);
      }}
      value={value}
    >
      <Select.Trigger
        aria-label={`${label} : ${selected?.label ?? value}`}
        className={cn(
          "inline-flex min-h-10 min-w-0 items-center gap-2 rounded-lg border px-3 text-left text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 data-[state=open]:border-accent",
          highlighted ? "border-accent-text/70 bg-accent/20" : "border-border bg-background hover:border-accent/60",
          className,
        )}
      >
        <span className="shrink-0 text-muted-foreground">{label}</span>
        <span className={cn("min-w-0 flex-1 truncate font-semibold", highlighted ? "text-accent-text" : "text-foreground")}>
          <Select.Value>{selected?.label ?? value}</Select.Value>
        </span>
        <Select.Icon asChild>
          <ChevronDown aria-hidden className="size-4 shrink-0 text-muted-foreground" />
        </Select.Icon>
      </Select.Trigger>

      <Select.Portal>
        <Select.Content
          className="z-50 max-h-[var(--radix-select-content-available-height)] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-lg border border-border bg-surface shadow-xl data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
          position="popper"
          sideOffset={6}
        >
          <Select.Viewport className="p-1">
            {options.map((option) => (
              <Select.Item
                className="relative flex cursor-pointer select-none items-center rounded-md py-2 pl-3 pr-8 text-sm text-foreground outline-none data-[highlighted]:bg-surface-2 data-[state=checked]:font-semibold data-[state=checked]:text-accent-text"
                key={option.value}
                value={option.value}
              >
                <Select.ItemText>{option.label}</Select.ItemText>
                <Select.ItemIndicator className="absolute right-2 inline-flex items-center">
                  <Check aria-hidden className="size-4" />
                </Select.ItemIndicator>
              </Select.Item>
            ))}
          </Select.Viewport>
        </Select.Content>
      </Select.Portal>
    </Select.Root>
  );
}
