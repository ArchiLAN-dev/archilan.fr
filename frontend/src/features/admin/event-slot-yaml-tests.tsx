"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { CheckCircle2, FileWarning, FlaskConical, Loader2, XCircle } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { hasRefusedFile } from "@/features/games/required-file-check";
import { RequiredFilesPanel, useProvidedFiles } from "@/features/games/required-files-panel";
import { slotTestBlocker, slotTestFiles } from "@/features/personal-runs/slot-test-files-dialog";
import type { SessionSlot } from "./admin-sessions-api";
import { getEventSlotYamlTest, startEventSlotYamlTest } from "./event-slot-yaml-test-api";
import {
  MAX_PARALLEL_SLOT_TESTS,
  SLOT_TEST_MAX_POLLS,
  SLOT_TEST_POLL_MS,
  runSlotTestPool,
  slotTestSummary,
  slotsToTestAll,
  stateAfterPoll,
  stateAfterStart,
  type SlotTestState,
} from "./event-slot-yaml-test-queue";

/** A slot the test can reach: its registration slot is known (sessions created before that carry none). */
type TestableSlot = SessionSlot & { slotId: string };

function isTestable(slot: SessionSlot): slot is TestableSlot {
  return slot.slotId !== null;
}

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Story 11.11: the solo tests of a session's slots, as « Tester ma config » does for a private game. Nothing is
 * stored: the verdicts live in the page, and at most MAX_PARALLEL_SLOT_TESTS run at once on the shared runner.
 */
export function useEventSlotYamlTests(eventId: string, slots: SessionSlot[]) {
  const [states, setStates] = useState<Record<string, SlotTestState>>({});
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  const testable = useMemo(() => slots.filter(isTestable), [slots]);
  const set = useCallback((key: string, state: SlotTestState) => {
    if (alive.current) setStates((current) => ({ ...current, [key]: state }));
  }, []);

  const test = useCallback(
    async (slot: TestableSlot, files: Record<string, File> = {}): Promise<void> => {
      set(slot.id, { kind: "running" });
      const start = await startEventSlotYamlTest(eventId, slot.registrationId, slot.slotId, files);
      if (start.kind !== "started") {
        set(slot.id, stateAfterStart(start));
        return;
      }
      for (let polls = 0; polls < SLOT_TEST_MAX_POLLS && alive.current; polls += 1) {
        await wait(SLOT_TEST_POLL_MS);
        const settled = stateAfterPoll(await getEventSlotYamlTest(eventId, start.jobId));
        if (settled !== null) {
          set(slot.id, settled);
          return;
        }
      }
      set(slot.id, { kind: "error", message: "Le test n'a pas répondu à temps : relance-le." });
    },
    [eventId, set],
  );

  const testAll = useCallback(async (): Promise<void> => {
    const byId = new Map(testable.map((slot) => [slot.id, slot]));
    const keys = slotsToTestAll(
      testable.map((slot) => slot.id),
      states,
    );
    setStates((current) => ({ ...current, ...Object.fromEntries(keys.map((key) => [key, { kind: "queued" } satisfies SlotTestState])) }));
    await runSlotTestPool(
      keys,
      MAX_PARALLEL_SLOT_TESTS,
      async (key) => {
        const slot = byId.get(key);
        if (slot !== undefined) await test(slot);
      },
      () => alive.current,
    );
  }, [states, test, testable]);

  return { states, test, testAll, testable };
}

type Tests = ReturnType<typeof useEventSlotYamlTests>;

/** « Tester toutes les configs », the tally, and the slots that failed with their error. */
export function EventSlotYamlTestsToolbar({ tests, slots }: { tests: Tests; slots: SessionSlot[] }) {
  const summary = slotTestSummary(tests.states);
  const busy = summary.pending > 0;
  const failed = slots.filter((slot) => tests.states[slot.id]?.kind === "failed" || tests.states[slot.id]?.kind === "error");
  const started = Object.keys(tests.states).length > 0;

  if (tests.testable.length === 0) return null;

  return (
    <div className="grid gap-3 rounded border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center gap-3">
        <button className={buttonVariants({ variant: "secondary" })} disabled={busy} onClick={() => void tests.testAll()} type="button">
          {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <FlaskConical aria-hidden className="size-4" />}
          Tester toutes les configs
        </button>
        <p className="text-sm text-muted-foreground">
          {started
            ? `${summary.passed} testée${summary.passed > 1 ? "s" : ""}, ${summary.failed} en échec, ${summary.needsFile} avec fichier à fournir${summary.pending > 0 ? `, ${summary.pending} en cours` : ""}${summary.errors > 0 ? `, ${summary.errors} sans réponse` : ""}.`
            : `Génère chaque slot seul avec le YAML de son inscription, ${MAX_PARALLEL_SLOT_TESTS} à la fois : un échec désigne le YAML à corriger.`}
        </p>
      </div>
      {failed.length > 0 ? (
        <ul className="grid gap-2 text-sm">
          {failed.map((slot) => {
            const state = tests.states[slot.id];
            const message = state?.kind === "failed" ? state.error : state?.kind === "error" ? state.message : "";
            return (
              <li className="rounded border border-danger/40 bg-danger/10 px-3 py-2" key={slot.id}>
                <span className="font-mono font-semibold text-foreground">{slot.slotName}</span>
                <span className="ml-2 break-words text-danger">{message}</span>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}

/** The « Config » cell of a slot: its verdict and its own test button. */
export function EventSlotYamlTestCell({ tests, slot }: { tests: Tests; slot: SessionSlot }) {
  const [fileDialogOpen, setFileDialogOpen] = useState(false);
  if (!isTestable(slot)) return null;
  const state = tests.states[slot.id] ?? { kind: "idle" };
  const busy = state.kind === "queued" || state.kind === "running";

  return (
    <div className="flex flex-wrap items-center gap-2">
      <SlotTestVerdict state={state} />
      {state.kind === "needs_file" ? (
        <button className={buttonVariants({ variant: "ghost" })} onClick={() => setFileDialogOpen(true)} type="button">
          <FlaskConical aria-hidden className="size-4" />
          Tester avec le fichier
        </button>
      ) : (
        <button
          aria-label={`Tester la config de ${slot.slotName}`}
          className={buttonVariants({ variant: "ghost" })}
          disabled={busy}
          onClick={() => void tests.test(slot)}
          title="Génère ce slot seul, avec une seed : un échec signale un YAML à corriger ; une réussite ne garantit pas la génération complète."
          type="button"
        >
          <FlaskConical aria-hidden className="size-4" />
          Tester
        </button>
      )}
      {state.kind === "needs_file" ? (
        <SlotTestFileDialog
          onLaunch={(files) => void tests.test(slot, files)}
          onOpenChange={setFileDialogOpen}
          open={fileDialogOpen}
          requiredFiles={state.requiredFiles}
          slotName={slot.slotName}
        />
      ) : null}
    </div>
  );
}

function SlotTestVerdict({ state }: { state: SlotTestState }) {
  switch (state.kind) {
    case "idle":
      return null;
    case "queued":
      return <span className="text-xs text-muted-foreground">En attente…</span>;
    case "running":
      return (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
          <Loader2 aria-hidden className="size-3 animate-spin" />
          Test en cours…
        </span>
      );
    case "passed":
      return (
        <span className="inline-flex items-center gap-1 text-xs text-success" title="Testé seul avec une seed - la génération complète peut encore différer.">
          <CheckCircle2 aria-hidden className="size-3" />
          Config testée
        </span>
      );
    case "failed":
      return (
        <span className="inline-flex items-center gap-1 text-xs text-danger" title={state.error}>
          <XCircle aria-hidden className="size-3" />
          Échec
        </span>
      );
    case "needs_file":
      return (
        <span className="inline-flex items-center gap-1 text-xs text-accent-warm">
          <FileWarning aria-hidden className="size-3" />
          Fichier requis
        </span>
      );
    case "error":
      return (
        <span className="text-xs text-danger" title={state.message}>
          Non testé
        </span>
      );
  }
}

type FileDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  slotName: string;
  requiredFiles: unknown;
  onLaunch: (files: Record<string, File>) => void;
};

/** The file a game reads at generation (a ROM), checked in the browser, then the test. */
function SlotTestFileDialog({ open, onOpenChange, slotName, requiredFiles, onLaunch }: FileDialogProps) {
  return (
    <Dialog
      description="Le slot est généré seul, avec une seed : un échec signale un YAML à corriger."
      onOpenChange={onOpenChange}
      open={open}
      title={`Tester la config - ${slotName}`}
    >
      <SlotTestFileForm
        onClose={() => onOpenChange(false)}
        onLaunch={onLaunch}
        requiredFiles={requiredFiles}
      />
    </Dialog>
  );
}

function SlotTestFileForm({ requiredFiles, onClose, onLaunch }: { requiredFiles: unknown; onClose: () => void; onLaunch: (files: Record<string, File>) => void }) {
  const needed = useMemo(() => slotTestFiles(requiredFiles), [requiredFiles]);
  const provided = useProvidedFiles(needed);
  const blocker = slotTestBlocker(needed, provided.states);

  return (
    <>
      <DialogBody>
        <RequiredFilesPanel
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
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className={buttonVariants({ variant: "primary" })}
          disabled={blocker !== null}
          onClick={() => {
            onLaunch(provided.files);
            onClose();
          }}
          type="button"
        >
          <FlaskConical aria-hidden className="size-4" />
          Lancer le test
        </button>
      </DialogFooter>
    </>
  );
}
