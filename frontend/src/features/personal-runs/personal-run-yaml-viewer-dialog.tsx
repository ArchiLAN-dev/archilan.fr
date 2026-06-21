"use client";

import { useEffect, useState } from "react";
import { FileCode2, LayoutList, X } from "lucide-react";

import { YamlOptionsView, parseGameOptions } from "@/components/yaml/yaml-options-view";

type Tab = "visual" | "text";

function VisualView({ gameName, playerYaml }: { gameName: string; playerYaml: string }) {
  const gameOptions = parseGameOptions(playerYaml, gameName);

  if (gameOptions === null || Object.keys(gameOptions).length === 0) {
    return (
      <div className="grid gap-3">
        <p className="text-sm text-muted-foreground">
          Cette configuration n&apos;a pas pu être interprétée visuellement. Voici le contenu brut :
        </p>
        <pre className="max-h-[55vh] overflow-auto whitespace-pre-wrap break-words rounded bg-background p-3 text-xs text-muted-foreground">
          {playerYaml}
        </pre>
      </div>
    );
  }

  return <YamlOptionsView gameName={gameName} yamlConfig={playerYaml} />;
}

export function PersonalRunYamlViewerDialog({
  gameName,
  playerYaml,
  onClose,
}: {
  gameName: string;
  playerYaml: string;
  onClose: () => void;
}) {
  const [tab, setTab] = useState<Tab>("visual");

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button aria-label="Fermer" className="absolute inset-0 cursor-default bg-black/60" onClick={onClose} type="button" />
      <div className="relative flex max-h-[88vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-xl">
        <div className="flex items-center justify-between gap-3 border-b border-border p-4">
          <div className="min-w-0">
            <h3 className="truncate font-heading text-base font-semibold text-foreground">{gameName}</h3>
            <p className="text-xs text-muted-foreground">Configuration YAML (lecture seule)</p>
          </div>
          <button
            aria-label="Fermer"
            className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden className="size-4" />
          </button>
        </div>

        <div className="flex gap-1 border-b border-border px-4 pt-3">
          <button
            className={[
              "inline-flex items-center gap-1.5 rounded-t border-b-2 px-3 py-2 text-sm font-medium transition-colors",
              tab === "visual"
                ? "border-accent text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            ].join(" ")}
            onClick={() => setTab("visual")}
            type="button"
          >
            <LayoutList aria-hidden className="size-4" />
            Visuel
          </button>
          <button
            className={[
              "inline-flex items-center gap-1.5 rounded-t border-b-2 px-3 py-2 text-sm font-medium transition-colors",
              tab === "text"
                ? "border-accent text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            ].join(" ")}
            onClick={() => setTab("text")}
            type="button"
          >
            <FileCode2 aria-hidden className="size-4" />
            Texte
          </button>
        </div>

        <div className="overflow-y-auto p-4">
          {tab === "visual" ? (
            <VisualView gameName={gameName} playerYaml={playerYaml} />
          ) : (
            <pre className="overflow-auto whitespace-pre-wrap break-words rounded bg-background p-3 text-xs text-muted-foreground">
              {playerYaml}
            </pre>
          )}
        </div>
      </div>
    </div>
  );
}