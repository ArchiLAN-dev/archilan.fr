"use client";

import { useCallback, useMemo, useState } from "react";
import { AlertCircle, CheckCircle2, FileUp, Loader2 } from "lucide-react";
import {
  acceptedFiles,
  checkProvidedFile,
  filesToProvide,
  launchBlocker,
  providedFileMessage,
  type ProvidedFileState,
} from "./required-file-check";
import { requiredFileLabel, type GameRequiredFile, type GenerationFile } from "./required-files";

/**
 * Story 44.5: the files a launch must carry, chosen and checked in the browser. The `File` objects live in
 * this state only - never in a query cache nor in browser storage - and are dropped with the page.
 */
export function useProvidedFiles(declared: GenerationFile[]) {
  const needed = useMemo(() => filesToProvide(declared), [declared]);
  const [states, setStates] = useState<Record<string, ProvidedFileState>>({});

  const choose = useCallback(async (required: GameRequiredFile, file: File) => {
    setStates((current) => ({ ...current, [required.setting]: { kind: "checking", progress: 0 } }));
    const state = await checkProvidedFile(required, file, (progress) =>
      setStates((current) => ({ ...current, [required.setting]: { kind: "checking", progress } })),
    );
    setStates((current) => ({ ...current, [required.setting]: state }));
  }, []);

  const reset = useCallback(() => setStates({}), []);

  return {
    needed,
    states,
    choose,
    reset,
    blocker: launchBlocker(needed, states),
    files: acceptedFiles(states),
  };
}

type Props = {
  needed: GenerationFile[];
  states: Record<string, ProvidedFileState>;
  onChoose: (required: GameRequiredFile, file: File) => void;
  disabled?: boolean;
};

/** One row per file a generation needs; nothing at all when none is needed. */
export function RequiredFilesPanel({ needed, states, onChoose, disabled = false }: Props) {
  if (needed.length === 0) return null;

  return (
    <section aria-labelledby="required-files-panel-title" className="grid gap-3 rounded-lg border border-accent-warm/40 bg-accent-warm/10 p-4">
      <div>
        <h3 className="font-heading text-base font-semibold text-foreground" id="required-files-panel-title">
          Fichiers à fournir
        </h3>
        <p className="mt-1 text-sm text-muted-foreground">
          Un des jeux lit un fichier à toi pendant la génération, une ROM le plus souvent. Il est vérifié ici, envoyé
          pour cette génération seulement, puis supprimé : ArchiLAN ne le garde jamais.
        </p>
      </div>
      <ul className="grid gap-3">
        {needed.map((file) => (
          <RequiredFileRow
            disabled={disabled}
            file={file}
            key={file.setting}
            onChoose={onChoose}
            state={states[file.setting] ?? { kind: "missing" }}
          />
        ))}
      </ul>
    </section>
  );
}

function RequiredFileRow({
  file,
  state,
  onChoose,
  disabled,
}: {
  file: GenerationFile;
  state: ProvidedFileState;
  onChoose: (required: GameRequiredFile, file: File) => void;
  disabled: boolean;
}) {
  const inputId = `required-file-${file.setting}`;
  const tone =
    state.kind === "accepted" ? "text-success" : state.kind === "wrong" || state.kind === "tooLarge" ? "text-danger" : "text-muted-foreground";

  return (
    <li className="grid gap-2 rounded border border-border bg-surface p-3">
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-semibold text-foreground">{requiredFileLabel(file)}</span>
        {file.extension !== "" ? <span className="text-sm text-muted-foreground">{file.extension}</span> : null}
        {file.games.length > 0 ? <span className="text-sm text-muted-foreground">· {file.games.join(", ")}</span> : null}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <label
          className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded border border-border bg-background px-3 text-sm font-semibold text-foreground hover:border-accent aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
          aria-disabled={disabled || state.kind === "checking"}
          htmlFor={inputId}
        >
          {state.kind === "checking" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <FileUp aria-hidden className="size-4" />}
          {state.kind === "accepted" ? "Changer de fichier" : "Choisir le fichier"}
        </label>
        <input
          accept={file.extension !== "" ? file.extension : undefined}
          className="sr-only"
          disabled={disabled || state.kind === "checking"}
          id={inputId}
          onChange={(event) => {
            const chosen = event.target.files?.[0];
            event.target.value = "";
            if (chosen) onChoose(file, chosen);
          }}
          type="file"
        />
        <span className={`inline-flex items-center gap-1 text-sm ${tone}`}>
          {state.kind === "accepted" ? <CheckCircle2 aria-hidden className="size-4" /> : null}
          {state.kind === "wrong" || state.kind === "tooLarge" ? <AlertCircle aria-hidden className="size-4" /> : null}
          <span className="break-all">{providedFileMessage(state)}</span>
        </span>
      </div>
    </li>
  );
}
