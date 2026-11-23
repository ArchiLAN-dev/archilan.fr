import { Ruler } from "lucide-react";

import type { OptionPreset } from "@/lib/archipelago-yaml";

type Props = {
  presets: OptionPreset[];
  onApply: (preset: OptionPreset) => void;
  /** What the last applied preset changed, or null. */
  note: string | null;
};

/**
 * Story 56.5: the admin's formats for a game (short, medium, long), applied to the player's YAML in one click. They
 * set option values, never the text of the file: the player can change any of them afterwards.
 */
export function OptionPresetBar({ presets, onApply, note }: Props) {
  return (
    <div className="mb-4 grid gap-2 rounded-md border border-border bg-surface p-3">
      <p className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
        <Ruler aria-hidden="true" className="size-4" />
        Formats tout prêts
      </p>
      <div className="flex flex-wrap gap-2">
        {presets.map((preset) => (
          <button
            className="inline-flex min-h-9 items-center rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
            key={preset.name}
            onClick={() => onApply(preset)}
            type="button"
          >
            {preset.name}
          </button>
        ))}
      </div>
      {note !== null ? (
        <p className="text-xs text-muted-foreground" role="status">
          {note}
        </p>
      ) : null}
    </div>
  );
}
