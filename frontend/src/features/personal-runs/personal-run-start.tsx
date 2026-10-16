"use client";

import { useMemo, useState } from "react";
import { Loader2, Play } from "lucide-react";
import { RequiredFilesPanel, useProvidedFiles } from "@/features/games/required-files-panel";
import { asGenerationFiles } from "@/features/games/required-files";
import { startRun } from "./personal-runs-api";

type Props = {
  runId: string;
  // The run's `generationFiles` as the payload carries it (story 44.3), validated here.
  generationFiles: unknown;
  hasConfiguredGames: boolean;
  disabled: boolean;
  onBusy: (busy: boolean) => void;
  onError: (message: string | null) => void;
  onStarted: () => Promise<void>;
};

/**
 * The owner's "start the run" control. Story 44.5: when a game reads a file at generation (a ROM), the
 * panel asks for it and checks it in the browser; the run cannot start without it.
 */
export function PersonalRunStart({ runId, generationFiles, hasConfiguredGames, disabled, onBusy, onError, onStarted }: Props) {
  const declared = useMemo(() => asGenerationFiles(generationFiles), [generationFiles]);
  const provided = useProvidedFiles(declared);
  const [starting, setStarting] = useState(false);

  async function start() {
    setStarting(true);
    onBusy(true);
    onError(null);
    const result = await startRun(runId, provided.files);
    if (result.ok) {
      // The file went with the launch: nothing is kept for later.
      provided.reset();
      await onStarted();
    } else {
      // The chosen file stays, so the launcher can retry as is.
      onError(result.message);
    }
    setStarting(false);
    onBusy(false);
  }

  const blocked = !hasConfiguredGames || provided.blocker !== null;

  return (
    <>
      <RequiredFilesPanel disabled={starting} needed={provided.needed} onChoose={(required, file) => void provided.choose(required, file)} states={provided.states} />
      <button
        className="inline-flex w-full items-center justify-center gap-2 rounded bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
        disabled={disabled || blocked || starting}
        onClick={() => void start()}
        title={!hasConfiguredGames ? "Configure au moins un jeu pour pouvoir démarrer" : (provided.blocker ?? undefined)}
        type="button"
      >
        {starting ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Play aria-hidden className="size-4" />}
        Démarrer la partie
      </button>
      {!hasConfiguredGames ? (
        <p className="text-center text-xs text-muted-foreground">Configure au moins un jeu pour pouvoir démarrer.</p>
      ) : provided.blocker !== null ? (
        <p className="text-center text-xs text-muted-foreground">{provided.blocker}</p>
      ) : null}
    </>
  );
}
