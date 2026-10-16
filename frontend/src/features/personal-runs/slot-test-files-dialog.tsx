"use client";

import { useMemo, useState } from "react";
import { FlaskConical, Loader2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { filesToProvide, hasRefusedFile, type ProvidedFileState } from "@/features/games/required-file-check";
import { RequiredFilesPanel, useProvidedFiles } from "@/features/games/required-files-panel";
import { asGameRequiredFiles, requiredFileLabel, type GenerationFile } from "@/features/games/required-files";
import { requestSlotPreflight } from "./personal-runs-api";

/** Story 44.7: the files a slot's test must carry - those a failed generation proved necessary. */
export function slotTestFiles(requiredFiles: unknown): GenerationFile[] {
  return filesToProvide(asGameRequiredFiles(requiredFiles)).map((file) => ({ ...file, games: [] }));
}

/** Why the test cannot start yet: without the file it could only fail on its absence. */
export function slotTestBlocker(needed: GenerationFile[], states: Record<string, ProvidedFileState>): string | null {
  for (const file of needed) {
    const state = states[file.setting] ?? { kind: "missing" };
    if (state.kind === "checking") return "Vérification du fichier en cours…";
    if (state.kind !== "accepted") return `Choisis le fichier « ${requiredFileLabel(file)} » pour lancer le test.`;
  }
  return null;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  runId: string;
  slotId: string;
  gameName: string;
  requiredFiles: unknown;
  onLaunched: () => void;
};

/** « Tester ma config » for a game that reads a ROM: the file first, checked in the browser, then the test. */
export function SlotTestFilesDialog({ open, onOpenChange, runId, slotId, gameName, requiredFiles, onLaunched }: Props) {
  return (
    <Dialog
      description="Ta config est générée seule, avec une seed : un échec signale un YAML à corriger."
      onOpenChange={onOpenChange}
      open={open}
      title={`Tester ma config - ${gameName}`}
    >
      <SlotTestFilesForm onClose={() => onOpenChange(false)} onLaunched={onLaunched} requiredFiles={requiredFiles} runId={runId} slotId={slotId} />
    </Dialog>
  );
}

function SlotTestFilesForm({
  runId,
  slotId,
  requiredFiles,
  onClose,
  onLaunched,
}: Pick<Props, "runId" | "slotId" | "requiredFiles" | "onLaunched"> & { onClose: () => void }) {
  const needed = useMemo(() => slotTestFiles(requiredFiles), [requiredFiles]);
  const provided = useProvidedFiles(needed);
  const blocker = slotTestBlocker(needed, provided.states);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function launch(): Promise<void> {
    setSending(true);
    setError(null);
    const result = await requestSlotPreflight(runId, slotId, provided.files);
    setSending(false);
    if (result.ok) {
      provided.reset();
      onLaunched();
      onClose();
      return;
    }
    setError(result.message);
  }

  return (
    <>
      <DialogBody>
        <RequiredFilesPanel
          disabled={sending}
          intro="Le jeu lit ce fichier pendant la génération : le test ne peut pas tourner sans lui. Il est vérifié ici, envoyé pour ce test seulement, puis supprimé : ArchiLAN ne le garde jamais."
          needed={needed}
          onChoose={(required, file) => void provided.choose(required, file)}
          states={provided.states}
          variant="plain"
        />
        {blocker !== null ? (
          <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            {blocker}
            {hasRefusedFile(provided.states) ? (
              <button className={buttonVariants({ variant: "ghost" })} onClick={provided.reset} type="button">
                Retirer le fichier
              </button>
            ) : null}
          </p>
        ) : null}
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
          Annuler
        </button>
        <button className={buttonVariants({ variant: "primary" })} disabled={sending || blocker !== null} onClick={() => void launch()} type="button">
          {sending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <FlaskConical aria-hidden className="size-4" />}
          Lancer le test
        </button>
      </DialogFooter>
    </>
  );
}
