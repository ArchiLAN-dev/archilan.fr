"use client";

import { useMemo, useState } from "react";
import { AlertCircle, FlaskConical, Info, Upload } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { GameYamlTestOutcome, useGameYamlTest } from "@/features/games/game-yaml-test";
import { checkImportedYaml, IMPORT_MAX_BYTES, type ImportIssue } from "@/lib/archipelago-yaml-import";
import { serializeToYaml, type ParsedYaml } from "@/lib/archipelago-yaml";

type Props = {
  open: boolean;
  onClose: () => void;
  /** The game's default YAML, parsed with its option types: what the import is checked against and applied over. */
  base: ParsedYaml;
  locationNames?: readonly string[] | null;
  /** When known, the checked YAML can be test-generated before it is applied. */
  gameId?: string;
  onApply: (parsed: ParsedYaml) => void;
};

/**
 * Story 9.57: import a YAML written elsewhere into the option editor. The text is checked as it is typed or
 * loaded, and only a YAML without error can be applied; nothing is saved until the editor is.
 */
export function YamlImportDialog({ open, onClose, base, locationNames, gameId, onApply }: Props) {
  return (
    <Dialog
      description={`Un fichier .yaml ou un texte collé, pour ${base.gameName}. Les options absentes du fichier gardent leur valeur par défaut.`}
      onOpenChange={(next) => (next ? null : onClose())}
      open={open}
      size="wide"
      title="Importer un YAML"
    >
      <ImportForm base={base} gameId={gameId} locationNames={locationNames} onApply={onApply} onClose={onClose} />
    </Dialog>
  );
}

function ImportForm({ base, locationNames, gameId, onApply, onClose }: Omit<Props, "open">) {
  const [text, setText] = useState("");
  const [fileError, setFileError] = useState<string | null>(null);
  const test = useGameYamlTest(gameId);

  const check = useMemo(
    () => (text.trim() === "" ? null : checkImportedYaml(text, base, { locationNames })),
    [text, base, locationNames],
  );
  const result = check?.result ?? null;

  async function readFile(file: File | undefined) {
    if (file === undefined) return;
    if (file.size > IMPORT_MAX_BYTES) {
      setFileError("Le fichier dépasse 100 Ko.");
      return;
    }
    setFileError(null);
    test.reset();
    setText(await file.text());
  }

  return (
    <>
      <DialogBody>
        <label className="inline-flex w-fit cursor-pointer items-center gap-2 rounded border border-border bg-background px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent">
          <Upload aria-hidden className="size-4" />
          Choisir un fichier
          <input
            accept=".yaml,.yml,text/yaml,application/x-yaml"
            className="sr-only"
            type="file"
            onChange={(e) => void readFile(e.target.files?.[0])}
          />
        </label>
        {fileError !== null ? <p className="text-sm text-danger" role="alert">{fileError}</p> : null}
        <textarea
          aria-label="YAML à importer"
          className="min-h-56 w-full rounded border border-border bg-background p-3 font-mono text-xs text-foreground outline-none focus:border-accent"
          placeholder={`name: MonPseudo\ngame: ${base.gameName}\n${base.gameName}:\n  ...`}
          spellCheck={false}
          value={text}
          onChange={(e) => {
            setText(e.target.value);
            test.reset();
          }}
        />
        {check !== null ? <ImportReport errors={check.errors} warnings={check.warnings} /> : null}
        {result !== null && gameId !== undefined ? <GameYamlTestOutcome state={test.state} /> : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} type="button" onClick={onClose}>
          Annuler
        </button>
        {gameId !== undefined ? (
          <button
            className={buttonVariants({ variant: "secondary" })}
            disabled={result === null || test.busy}
            type="button"
            onClick={() => {
              if (result !== null) void test.start(serializeToYaml(result));
            }}
          >
            <FlaskConical aria-hidden className="size-4" />
            Tester la génération
          </button>
        ) : null}
        <button
          className={buttonVariants({ variant: "primary" })}
          disabled={result === null}
          type="button"
          onClick={() => {
            if (result === null) return;
            onApply(result);
            onClose();
          }}
        >
          Appliquer
        </button>
      </DialogFooter>
    </>
  );
}

function ImportReport({ errors, warnings }: { errors: ImportIssue[]; warnings: ImportIssue[] }) {
  if (errors.length === 0 && warnings.length === 0) {
    return <p className="text-sm text-success" role="status">Structure valide : ce YAML peut être appliqué.</p>;
  }

  return (
    <div className="grid gap-2">
      {errors.length > 0 ? (
        <IssueList
          icon={<AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />}
          issues={errors}
          title="À corriger avant d'importer :"
          tone="text-danger"
        />
      ) : null}
      {warnings.length > 0 ? (
        <IssueList
          icon={<Info aria-hidden className="mt-0.5 size-4 shrink-0" />}
          issues={warnings}
          title="Ignoré à l'import :"
          tone="text-muted-foreground"
        />
      ) : null}
    </div>
  );
}

function IssueList({ icon, issues, title, tone }: { icon: React.ReactNode; issues: ImportIssue[]; title: string; tone: string }) {
  return (
    <div className={`grid gap-1 text-sm ${tone}`} role={tone === "text-danger" ? "alert" : undefined}>
      <span className="flex items-start gap-1.5 font-semibold">
        {icon}
        {title}
      </span>
      <ul className="grid gap-0.5 pl-6">
        {issues.map((issue) => (
          <li key={`${issue.path}:${issue.message}`}>
            {issue.path !== "" ? <code className="font-mono text-xs">{issue.path}</code> : null}
            {issue.path !== "" ? " : " : null}
            {issue.message}
          </li>
        ))}
      </ul>
    </div>
  );
}
