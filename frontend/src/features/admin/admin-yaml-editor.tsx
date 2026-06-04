"use client";

import { useEffect, useMemo, useState } from "react";

import {
  mergePlayerValues,
  parseDefaultYaml,
  serializeToYaml,
} from "@/lib/archipelago-yaml";
import type { GameOption, ParsedYaml } from "@/lib/archipelago-yaml";

type Props = {
  defaultYaml: string;
  initialYaml?: string | null;
  onChange: (value: string) => void;
  label?: string;
  error?: string | null;
};

export function AdminYamlEditor({
  defaultYaml,
  initialYaml,
  onChange,
  label = "Configuration YAML",
  error,
}: Props) {
  const initialParsed = useMemo(() => {
    const base = parseDefaultYaml(defaultYaml);
    if (!base) return null;
    if (!initialYaml) return base;
    const saved = parseDefaultYaml(initialYaml);
    return saved ? mergePlayerValues(base, saved) : base;
  }, [defaultYaml, initialYaml]);

  const [parsed, setParsed] = useState<ParsedYaml | null>(initialParsed);
  const [rawYaml, setRawYaml] = useState(initialYaml ?? defaultYaml);

  useEffect(() => {
    async function apply() {
      setParsed(initialParsed);
      const nextYaml = initialParsed ? serializeToYaml(initialParsed) : (initialYaml ?? defaultYaml);
      setRawYaml(nextYaml);
      onChange(nextYaml);
    }
    void apply();
  }, [defaultYaml, initialParsed, initialYaml, onChange]);

  function updateParsed(next: ParsedYaml) {
    setParsed(next);
    const serialized = serializeToYaml(next);
    setRawYaml(serialized);
    onChange(serialized);
  }

  function updateOption(key: string, value: string) {
    if (!parsed) return;
    updateParsed({
      ...parsed,
      options: parsed.options.map((option) => updateOptionValue(option, key, value)),
    });
  }

  if (!parsed) {
    return (
      <div className="flex flex-col gap-1.5">
        <label className="text-sm font-medium text-foreground">{label}</label>
        <textarea
          className={[
            "w-full rounded border bg-surface px-3 py-2 font-mono text-sm text-foreground",
            "placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent resize-y",
            error ? "border-danger" : "border-border",
          ].join(" ")}
          onChange={(e) => {
            setRawYaml(e.target.value);
            onChange(e.target.value);
          }}
          rows={16}
          spellCheck={false}
          value={rawYaml}
        />
        {error && <p className="text-xs text-danger">{error}</p>}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-sm font-medium text-foreground">{label}</label>
      <div className="grid gap-3 rounded border border-border bg-surface p-4">
        {parsed.options.map((option) => (
          <label className="grid gap-1 text-sm" key={option.key}>
            <span className="font-medium text-foreground">{option.label}</span>
            {option.description && (
              <span className="text-xs text-muted-foreground">{option.description}</span>
            )}
            <input
              className="rounded border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent"
              onChange={(e) => updateOption(option.key, e.target.value)}
              value={displayValue(option)}
            />
          </label>
        ))}
      </div>
      {error && <p className="text-xs text-danger">{error}</p>}
      <p className="text-xs text-muted-foreground">
        Le champ <code>name</code> sera remplace par le pseudo du joueur au lancement.
      </p>
    </div>
  );
}

function displayValue(option: GameOption): string {
  if (option.type === "text") return option.value;
  if (option.type === "toggle") return option.weightTrue >= option.weightFalse ? "true" : "false";
  if (option.type === "choice") return option.choices.find((choice) => choice.weight > 0)?.value ?? "";
  if (option.type === "range") return option.entries.find((entry) => entry.weight > 0)?.key ?? "";
  if (option.type === "plando_items") return `${option.entries.length} règle(s)`;
  if (option.type === "item_links") return `${option.entries.length} lien(s)`;
  if (option.type === "freeform" && option.kind === "list") return option.items.join(", ");
  if (option.type === "freeform" && option.kind === "dict") return option.entries.map((entry) => `${entry.k}:${entry.v}`).join(", ");
  return "";
}

function updateOptionValue(option: GameOption, key: string, value: string): GameOption {
  if (option.key !== key) return option;
  if (option.type === "text") return { ...option, value };
  if (option.type === "toggle") {
    const normalized = value.toLowerCase();
    const enabled = normalized === "true" || normalized === "1" || normalized === "oui";
    return { ...option, weightFalse: enabled ? 0 : 50, weightTrue: enabled ? 50 : 0 };
  }
  if (option.type === "choice") {
    return {
      ...option,
      choices: option.choices.map((choice) => ({ ...choice, weight: choice.value === value ? 50 : 0 })),
    };
  }
  if (option.type === "range") {
    return {
      ...option,
      entries: option.entries.map((entry) => ({ ...entry, weight: entry.key === value ? 50 : 0 })),
    };
  }
  if (option.type === "plando_items") return option;
  if (option.type === "item_links") return option;
  if (option.type === "freeform" && option.kind === "list") {
    return { ...option, items: value.split(",").map((item) => item.trim()).filter(Boolean) };
  }
  if (option.type === "freeform" && option.kind === "dict") {
    return {
      ...option,
      entries: value.split(",").map((pair, index) => {
        const [k = "", v = ""] = pair.split(":");
        return { id: `${option.key}-${index}`, k: k.trim(), v: v.trim() };
      }),
    };
  }
  return option;
}
