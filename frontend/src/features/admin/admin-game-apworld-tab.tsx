"use client";

import { useMemo, useState, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { FileCode2, FlaskConical, GitBranch, Info, Loader2, Plus, RefreshCw, ShieldAlert, ShieldOff, Upload } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { hasRefusedFile } from "@/features/games/required-file-check";
import { RequiredFilesPanel, useProvidedFiles } from "@/features/games/required-files-panel";
import { GenerationFailureNotice } from "@/features/games/generation-failure-notice";
import { requiredFileLabel, type GenerationFile } from "@/features/games/required-files";
import { parseDefaultYaml, type FreeformDictOption } from "@/lib/archipelago-yaml";
import {
  approveApworldCandidate,
  fetchAdminGame,
  fetchGithubApworldAssets,
  forceApworldCandidate,
  importApworldFromGithub,
  overrideApworldPreflight,
  regenerateDefaultYaml,
  rerunApworldPreflight,
  retryApworldCandidate,
  saveDefaultYaml,
  saveDictOptionValues,
  uploadApworldFile,
  type AdminGame,
  type ApworldCandidateActionResult,
  type ApworldPreflight,
  type ApworldReplaceResult,
  type DefaultYamlResult,
  type GithubApworldAsset,
} from "./admin-games-api";
import { AdminGameRequiredFiles } from "./admin-game-required-files";
import { passedWithFilesLabel, TEST_FILES_INTRO, testFilesBlocker, testFilesOfGame } from "./admin-game-test-files";
import { ApworldCandidateStatus } from "./apworld-candidate-status";
import { ApworldPreflightImage } from "./apworld-preflight-image";
import { overrideIsActive } from "./apworld-preflight-override";
import { ApworldPreflightWarning } from "./apworld-preflight-warning";
import { CandidateYamlTest } from "./candidate-yaml-test";

type Props = { game: AdminGame; onUpdate: (game: AdminGame) => void };

/**
 * The APWorld tab of a game (story 38.15): one card per subject, spaced, each with its own actions; the one-off
 * actions - a test generation, a YAML test, replacing the file - open in a modal instead of unfolding in the page.
 */
export function ApworldTab({ game, onUpdate }: Props) {
  return (
    <div className="grid gap-6">
      <LiveApworldCard game={game} onUpdate={onUpdate} />
      {game.apworldCandidate ? <CandidateCard game={game} onUpdate={onUpdate} /> : null}
      <AdminGameRequiredFiles gameId={game.id} onUpdate={onUpdate} requiredFiles={game.requiredFiles} />
      {game.isApworldReady ? <TemplateCard game={game} onUpdate={onUpdate} /> : null}
      {game.isApworldReady ? <DictValuesCard game={game} onUpdate={onUpdate} /> : null}
    </div>
  );
}

/** A card of the tab: its title, one line on what it is for, its actions on the right. */
export function TabCard({
  title,
  description,
  actions,
  children,
  labelId,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
  labelId: string;
}) {
  return (
    <section aria-labelledby={labelId} className="grid min-w-0 gap-4 rounded-lg border border-border bg-surface p-5 sm:p-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid min-w-0 gap-1">
          <h2 className="font-heading text-lg font-semibold text-foreground" id={labelId}>
            {title}
          </h2>
          {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
        </div>
        {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
      </header>
      {children}
    </section>
  );
}

// ─── Version en ligne ─────────────────────────────────────────────────────────

const preflightLabels: Record<ApworldPreflight["status"], { label: string; className: string }> = {
  pending: { label: "Test de génération en cours…", className: "text-muted-foreground" },
  passed: { label: "Test de génération réussi", className: "text-success" },
  failed: { label: "Échec du test de génération", className: "text-danger" },
  skipped: { label: "Test impossible (pas de template YAML)", className: "text-muted-foreground" },
};

const DATE_TIME = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
const DATE_SHORT = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

/** The verdict line: the pass obtained with the admin's file names it (story 44.6). */
export function preflightVerdictLabel(preflight: ApworldPreflight | null, game: Pick<AdminGame, "requiredFiles">): { label: string; className: string } {
  if (preflight === null) return { label: "Jamais testé", className: "text-muted-foreground" };
  const withFiles = passedWithFilesLabel(preflight, testFilesOfGame(game.requiredFiles));
  const base = preflightLabels[preflight.status];
  return withFiles !== null ? { label: withFiles, className: base.className } : base;
}

function LiveApworldCard({ game, onUpdate }: Props) {
  const queryClient = useQueryClient();
  const [testing, setTesting] = useState(false);
  const [replacing, setReplacing] = useState(false);
  const [confirmingOverride, setConfirmingOverride] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const preflight: ApworldPreflight | null = game.apworldPreflight ?? null;
  const verdict = preflightVerdictLabel(preflight, game);
  const liveTestFiles = useMemo(() => testFilesOfGame(game.requiredFiles), [game.requiredFiles]);

  async function refreshGame(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ["admin-game", game.id] });
  }

  async function setOverride(overridden: boolean): Promise<void> {
    setBusy(true);
    setError(null);
    if ((await overrideApworldPreflight(game.id, overridden)) !== null) {
      await refreshGame();
    } else {
      setError("Impossible d'appliquer la dérogation (runner indisponible ?).");
    }
    setBusy(false);
    setConfirmingOverride(false);
  }

  const replaceDialog = (
    <Dialog
      description="La nouvelle version passe d'abord son test de génération ; le jeu garde la version actuelle en attendant."
      onOpenChange={setReplacing}
      open={replacing}
      title={game.isApworldReady ? "Remplacer l'apworld" : "Ajouter un apworld"}
    >
      <ReplaceApworldForm
        game={game}
        onClose={() => setReplacing(false)}
        onReplaced={(updated) => {
          onUpdate(updated);
          setReplacing(false);
        }}
      />
    </Dialog>
  );

  if (!game.isApworldReady) {
    return (
      <TabCard
        actions={
          <button className={buttonVariants({ variant: "primary" })} onClick={() => setReplacing(true)} type="button">
            <Plus aria-hidden className="size-4" />
            Ajouter un apworld
          </button>
        }
        description="Aucun fichier .apworld : les joueurs ne peuvent pas personnaliser leur YAML pour ce jeu."
        labelId="apworld-live-title"
        title="Version en ligne"
      >
        {replaceDialog}
      </TabCard>
    );
  }

  const canOverride = preflight?.status === "failed" || preflight?.overridden === true;

  return (
    <TabCard
      actions={
        <>
          <button
            className={buttonVariants({ variant: "primary" })}
            disabled={preflight?.status === "pending"}
            onClick={() => setTesting(true)}
            type="button"
          >
            <FlaskConical aria-hidden className="size-4" />
            Tester la génération
          </button>
          <button className={buttonVariants()} onClick={() => setReplacing(true)} type="button">
            <Upload aria-hidden className="size-4" />
            Remplacer
          </button>
        </>
      }
      description="Le fichier .apworld que les joueurs reçoivent, et son test de génération."
      labelId="apworld-live-title"
      title="Version en ligne"
    >
      <dl className="grid gap-x-6 gap-y-3 rounded border border-border bg-background p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Fact label="Jeu Archipelago">
          {game.archipelagoGameName ? (
            <span className="text-foreground">{game.archipelagoGameName}</span>
          ) : (
            <span className="font-semibold text-danger">manquant, la génération échouera</span>
          )}
        </Fact>
        <Fact label="Téléversé le">{game.apworldUploadedAt ? DATE_TIME.format(new Date(game.apworldUploadedAt)) : "-"}</Fact>
        <Fact label="Empreinte SHA-256">
          <span className="font-mono" title={game.apworldHash ?? undefined}>
            {game.apworldHash ? `${game.apworldHash.slice(0, 12)}…` : "-"}
          </span>
        </Fact>
        <Fact label="Template YAML">
          {game.defaultYaml ? `${game.defaultYaml.split("\n").length} lignes` : <span className="font-semibold text-danger">absent</span>}
        </Fact>
      </dl>

      <div className="grid gap-2">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <p className={`text-sm font-semibold ${verdict.className}`}>
            {verdict.label}
            {preflight?.checkedAt ? (
              <span className="ml-2 text-xs font-normal text-muted-foreground">le {DATE_SHORT.format(new Date(preflight.checkedAt))}</span>
            ) : null}
          </p>
          {canOverride ? (
            <button
              className={buttonVariants({ variant: "ghost" })}
              disabled={busy}
              onClick={() => (preflight?.overridden ? void setOverride(false) : setConfirmingOverride(true))}
              type="button"
            >
              {preflight?.overridden ? <ShieldOff aria-hidden className="size-4" /> : <ShieldAlert aria-hidden className="size-4" />}
              {preflight?.overridden ? "Retirer la dérogation" : "Autoriser malgré l'échec"}
            </button>
          ) : null}
        </div>

        {preflight?.status === "passed" && preflight.warning ? <ApworldPreflightWarning warning={preflight.warning} /> : null}

        {/* Only a verdict that ran names an image: pending and skipped ones claim none (story 38.8 review). */}
        {preflight !== null && (preflight.status === "passed" || preflight.status === "failed") ? (
          <ApworldPreflightImage
            image={preflight.image ?? null}
            imageId={preflight.imageId ?? null}
            onCurrentImage={game.apworldPreflightOnCurrentImage ?? null}
            runtimeImage={game.archipelagoRuntime?.apImage ?? null}
            runtimeImageId={game.archipelagoRuntime?.apImageId ?? null}
          />
        ) : null}

        {overrideIsActive(preflight) ? (
          <p className="text-xs text-warning">Dérogation active : le jeu reste sélectionnable malgré le verdict.</p>
        ) : null}

        {preflight?.status === "failed" ? (
          <GenerationFailureNotice audience="admin" log={preflight.error} message={preflight.summary ?? ""} />
        ) : null}

        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}

        {game.apworldSourceUrl ? (
          <p className="truncate font-mono text-xs text-muted-foreground" title={game.apworldSourceUrl}>
            Source : {game.apworldSourceUrl}
          </p>
        ) : null}
      </div>

      <Dialog
        description="Une génération seule, avec les options par défaut du template et une seule seed."
        onOpenChange={setTesting}
        open={testing}
        title="Tester la génération"
      >
        <TestGenerationForm
          onClose={() => setTesting(false)}
          onLaunched={async () => {
            setTesting(false);
            await refreshGame();
          }}
          start={(files) => rerunApworldPreflight(game.id, files)}
          testFiles={liveTestFiles}
        />
      </Dialog>

      {replaceDialog}

      <ConfirmDialog
        confirmLabel="Autoriser"
        description="Le jeu redevient sélectionnable alors que son test de génération échoue : une partie qui l'inclut risque de ne pas se générer."
        icon={ShieldAlert}
        onConfirm={() => void setOverride(true)}
        onOpenChange={(open) => {
          if (!open && !busy) setConfirmingOverride(false);
        }}
        open={confirmingOverride}
        pending={busy}
        title="Autoriser ce jeu malgré l'échec ?"
        tone="danger"
      />
    </TabCard>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid min-w-0 gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="truncate text-sm text-muted-foreground">{children}</dd>
    </div>
  );
}

type TestGenerationFormProps = {
  testFiles: GenerationFile[];
  start: (files: Record<string, File>) => Promise<{ ok: true } | { ok: false; message: string }>;
  onClose: () => void;
  onLaunched: () => Promise<void>;
};

/**
 * A test generation modal: the ROM a ROM game needs (story 44.6), then the launch. Serves "Tester la génération"
 * on the served apworld and, story 38.28, "Relancer le test" on a new version.
 */
function TestGenerationForm({ testFiles, start, onClose, onLaunched }: TestGenerationFormProps) {
  const provided = useProvidedFiles(testFiles);
  const blocker = testFilesBlocker(provided.states);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function launch(): Promise<void> {
    setSending(true);
    setError(null);
    const result = await start(provided.files);
    setSending(false);
    if (result.ok) {
      provided.reset();
      await onLaunched();
      return;
    }
    setError(result.message);
  }

  return (
    <>
      <DialogBody>
        <p className="text-sm text-muted-foreground">
          Un échec signale un apworld inutilisable en l&apos;état ; une réussite ne garantit pas toutes les combinaisons
          d&apos;options. Le verdict s&apos;affiche sur la fiche dès qu&apos;il tombe, en une à cinq minutes.
        </p>
        <RequiredFilesPanel
          disabled={sending}
          intro={TEST_FILES_INTRO}
          needed={testFiles}
          variant="plain"
          onChoose={(required, file) => void provided.choose(required, file)}
          states={provided.states}
        />
        {blocker !== null ? (
          <p className="flex flex-wrap items-center gap-2 text-sm text-danger">
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

/** The "Remplacer l'apworld" modal: the GitHub release or a local file, and whether to hold a passed test. */
export function ReplaceApworldForm({ game, onClose, onReplaced }: { game: AdminGame; onClose: () => void; onReplaced: (game: AdminGame) => void }) {
  const readFiles = useMemo(() => testFilesOfGame(game.requiredFiles), [game.requiredFiles]);
  const [holdForApproval, setHoldForApproval] = useState(false);
  const [assets, setAssets] = useState<GithubApworldAsset[] | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [pending, setPending] = useState<"assets" | "import" | "upload" | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function settle(result: Promise<ApworldReplaceResult>, kind: "import" | "upload"): Promise<void> {
    setPending(kind);
    setError(null);
    const outcome = await result;
    setPending(null);
    if (outcome.ok) onReplaced(outcome.game);
    else setError(outcome.message);
  }

  async function listAssets(): Promise<void> {
    setPending("assets");
    setError(null);
    const result = await fetchGithubApworldAssets(game.id);
    setPending(null);
    if (result.ok) setAssets(result.assets);
    else setError(result.message);
  }

  return (
    <>
      <DialogBody>
        {game.apworldSourceUrl ? (
          <section className="grid gap-2 border-b border-border pb-4">
            <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <GitBranch aria-hidden className="size-4" />
              Depuis la release GitHub
            </h3>
            <p className="truncate font-mono text-xs text-muted-foreground" title={game.apworldSourceUrl}>
              {game.apworldSourceUrl}
            </p>
            {assets === null ? (
              <div>
                <button className={buttonVariants()} disabled={pending !== null} onClick={() => void listAssets()} type="button">
                  {pending === "assets" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <RefreshCw aria-hidden className="size-4" />}
                  Voir les fichiers de la dernière release
                </button>
              </div>
            ) : assets.length === 0 ? (
              <p className="text-sm text-danger">Aucun fichier .apworld dans la dernière release.</p>
            ) : (
              <ul className="grid gap-1.5">
                {assets.map((asset) => (
                  <li key={asset.downloadUrl}>
                    <button
                      className="flex w-full items-center justify-between gap-3 rounded border border-border bg-surface px-3 py-2 text-left text-sm transition-colors hover:border-accent disabled:opacity-60"
                      disabled={pending !== null}
                      onClick={() => void settle(importApworldFromGithub(game.id, asset, holdForApproval), "import")}
                      type="button"
                    >
                      <span className="min-w-0 truncate font-mono text-foreground">{asset.name}</span>
                      <span className="shrink-0 text-xs text-muted-foreground">
                        {asset.tag ? `${asset.tag} · ` : ""}
                        {(asset.size / 1024).toFixed(0)} Ko
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ) : null}

        <section className="grid gap-2 border-b border-border pb-4">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
            <FileCode2 aria-hidden className="size-4" />
            Depuis un fichier
          </h3>
          <div className="flex flex-wrap items-center gap-3">
            <label className={`${buttonVariants()} cursor-pointer`} htmlFor="apworld-file-input">
              <Upload aria-hidden className="size-4" />
              Choisir un fichier .apworld
            </label>
            <input
              accept=".apworld"
              className="sr-only"
              id="apworld-file-input"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              type="file"
            />
            <span className="min-w-0 truncate text-sm text-muted-foreground">{file?.name ?? "Aucun fichier choisi."}</span>
          </div>
        </section>

        {/* Story 38.14: test first, put online by hand. */}
        <label className="flex items-start gap-2 text-sm text-foreground">
          <input checked={holdForApproval} className="mt-0.5 size-4 accent-accent" onChange={(e) => setHoldForApproval(e.target.checked)} type="checkbox" />
          <span>
            Garder en test, je validerai moi-même
            <span className="block text-xs text-muted-foreground">
              Même si le test de génération passe, la nouvelle version attend que tu la mettes en ligne. Tu pourras la tester avec un YAML avant.
            </span>
          </span>
        </label>

        {/* Story 38.28: the first test leaves from the orchestrator at upload, without the file. */}
        {readFiles.length > 0 ? (
          <p className="flex items-start gap-2 rounded border border-border bg-surface-hover p-3 text-sm text-muted-foreground">
            <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>
              Ce jeu lit un fichier à la génération ({readFiles.map(requiredFileLabel).join(", ")}). Le premier test de la nouvelle version part
              sans lui et échouera : relance-le avec le fichier depuis « Nouvelle version ».
            </span>
          </p>
        ) : null}
        {pending === "import" ? (
          <p className="inline-flex items-center gap-2 text-sm text-muted-foreground" role="status">
            <Loader2 aria-hidden className="size-4 animate-spin" /> Import en cours…
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
        <button
          className={buttonVariants({ variant: "primary" })}
          disabled={file === null || pending !== null}
          onClick={() => {
            if (file !== null) void settle(uploadApworldFile(game.id, file, holdForApproval), "upload");
          }}
          type="button"
        >
          {pending === "upload" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Upload aria-hidden className="size-4" />}
          Envoyer le fichier
        </button>
      </DialogFooter>
    </>
  );
}

// ─── Nouvelle version ─────────────────────────────────────────────────────────

function CandidateCard({ game, onUpdate }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [testingYaml, setTestingYaml] = useState(false);
  const [retrying, setRetrying] = useState(false);
  // Story 38.28: a world that reads a file (a ROM) fails its retried test without it, so the retry asks for it.
  const retryFiles = useMemo(() => testFilesOfGame(game.requiredFiles, { includeDeclared: true }), [game.requiredFiles]);

  async function refreshGame(): Promise<void> {
    const refreshed = await fetchAdminGame(game.id);
    if (refreshed.kind === "ready") onUpdate(refreshed.game);
  }

  async function run(action: (gameId: string) => Promise<ApworldCandidateActionResult>) {
    setBusy(true);
    setError(null);
    const result = await action(game.id);
    if (!result.ok) setError(result.message);
    // The action changed the game (forced) or its candidate (retried): read both back.
    await refreshGame();
    setBusy(false);
  }

  return (
    <TabCard
      actions={
        <button className={buttonVariants()} onClick={() => setTestingYaml(true)} type="button">
          <FileCode2 aria-hidden className="size-4" />
          Tester avec un YAML
        </button>
      }
      description="La version qui attend son test, ou ta validation, avant de servir les joueurs."
      labelId="apworld-candidate-title"
      title="Nouvelle version"
    >
      <ApworldCandidateStatus
        busy={busy}
        candidate={game.apworldCandidate ?? null}
        onApprove={() => void run(approveApworldCandidate)}
        onForce={() => void run(forceApworldCandidate)}
        onRetry={() => {
          if (retryFiles.length > 0) setRetrying(true);
          else void run(retryApworldCandidate);
        }}
      />
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      {/* Story 38.14: try the candidate with a real YAML before it serves players. */}
      <Dialog
        description="Une génération de test avec cette nouvelle version ; rien n'est mis en ligne."
        onOpenChange={setTestingYaml}
        open={testingYaml}
        size="wide"
        title="Tester avec un YAML"
      >
        <CandidateYamlTest gameId={game.id} onClose={() => setTestingYaml(false)} requiredFiles={game.requiredFiles} />
      </Dialog>
      <Dialog
        description="Le test de génération de cette nouvelle version, avec le fichier que lit le jeu."
        onOpenChange={setRetrying}
        open={retrying}
        title="Relancer le test"
      >
        <TestGenerationForm
          onClose={() => setRetrying(false)}
          onLaunched={async () => {
            setRetrying(false);
            await refreshGame();
          }}
          start={(files) => retryApworldCandidate(game.id, files)}
          testFiles={retryFiles}
        />
      </Dialog>
    </TabCard>
  );
}

// ─── Template YAML ────────────────────────────────────────────────────────────

function TemplateCard({ game, onUpdate }: Props) {
  const [open, setOpen] = useState(false);
  const lines = game.defaultYaml ? game.defaultYaml.split("\n").length : 0;

  return (
    <TabCard
      actions={
        <button aria-controls="apworld-template-editor" aria-expanded={open} className={buttonVariants()} onClick={() => setOpen((o) => !o)} type="button">
          {open ? "Replier" : "Modifier"}
        </button>
      }
      description={
        lines > 0 ? (
          `${lines} lignes. Il pré-remplit chaque nouveau slot et sert de configuration par défaut au lancement.`
        ) : (
          <span className="font-semibold text-danger">Aucun template : les joueurs n&apos;ont rien pour démarrer.</span>
        )
      }
      labelId="apworld-template-title"
      title="Template YAML"
    >
      {/* Kept mounted while folded, so a draft survives folding the card. */}
      <div hidden={!open} id="apworld-template-editor">
        <DefaultYamlEditor game={game} onUpdate={onUpdate} />
      </div>
    </TabCard>
  );
}

/**
 * The default template is not a read-only artefact: it seeds every new player slot and is the fallback at launch, so
 * an invalid generated one (Atlyss ships one) has to be fixable here - and revertible to the generated version.
 */
function DefaultYamlEditor({ game, onUpdate }: Props) {
  const [draft, setDraft] = useState(game.defaultYaml ?? "");
  const [saving, setSaving] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [confirmingRegenerate, setConfirmingRegenerate] = useState(false);
  const [message, setMessage] = useState<{ kind: "ok" | "warn" | "error"; text: string } | null>(null);

  const stored = game.defaultYaml ?? "";
  const dirty = draft !== stored;

  function applyResult(result: DefaultYamlResult, okText: string): void {
    if (result.kind === "saved") {
      onUpdate(result.game);
      setDraft(result.game.defaultYaml ?? "");
      setMessage(result.warning !== null ? { kind: "warn", text: result.warning } : { kind: "ok", text: okText });
      return;
    }
    setMessage({ kind: "error", text: result.message });
  }

  async function handleSave(): Promise<void> {
    setSaving(true);
    setMessage(null);
    applyResult(await saveDefaultYaml(game.id, draft), "Template enregistré. Le test de génération a été relancé.");
    setSaving(false);
  }

  async function handleRegenerate(): Promise<void> {
    setRegenerating(true);
    setMessage(null);
    applyResult(await regenerateDefaultYaml(game.id), "Template régénéré depuis l'apworld.");
    setRegenerating(false);
    setConfirmingRegenerate(false);
  }

  return (
    <div className="grid gap-2">
      <p className="text-xs text-muted-foreground">
        Certains apworlds en génèrent un invalide : corrige-le ici. L&apos;enregistrer relance le test de génération.
        {dirty ? <span className="ml-2 text-warning">Modifications non enregistrées</span> : null}
      </p>
      <textarea
        aria-label="Template YAML"
        className="min-h-80 rounded border border-border bg-background p-3 font-mono text-xs text-foreground outline-none focus:border-accent"
        onChange={(e) => setDraft(e.target.value)}
        spellCheck={false}
        value={draft}
      />
      <div className="flex flex-wrap items-center gap-2">
        <button className={buttonVariants({ variant: "primary" })} disabled={!dirty || saving || regenerating} onClick={() => void handleSave()} type="button">
          {saving ? "Enregistrement…" : "Enregistrer"}
        </button>
        <button className={buttonVariants()} disabled={saving || regenerating} onClick={() => setConfirmingRegenerate(true)} type="button">
          {regenerating ? "Régénération…" : "Réinitialiser depuis l'apworld"}
        </button>
        {dirty ? (
          <button className={buttonVariants({ variant: "ghost" })} onClick={() => setDraft(stored)} type="button">
            Annuler mes modifications
          </button>
        ) : null}
      </div>
      {message !== null ? (
        <p
          className={`text-xs ${message.kind === "error" ? "text-danger" : message.kind === "warn" ? "text-warning" : "text-success"}`}
          role={message.kind === "error" ? "alert" : undefined}
        >
          {message.text}
        </p>
      ) : null}
      <ConfirmDialog
        confirmLabel="Régénérer"
        description="Le template est recalculé depuis l'apworld : les modifications manuelles enregistrées seront perdues."
        icon={RefreshCw}
        onConfirm={() => void handleRegenerate()}
        onOpenChange={(isOpen) => {
          if (!isOpen && !regenerating) setConfirmingRegenerate(false);
        }}
        open={confirmingRegenerate}
        pending={regenerating}
        title="Réinitialiser le template depuis l'apworld ?"
        tone="danger"
      />
    </div>
  );
}

// ─── Valeurs des réglages groupés (story 9.52) ───────────────────────────────

/** The dict options of the template that list sub-settings, parsed as the player editor parses them. */
export function dictOptionsOf(game: Pick<AdminGame, "defaultYaml" | "optionTypes">): FreeformDictOption[] {
  const parsed = parseDefaultYaml(game.defaultYaml ?? "", game.optionTypes ?? null);
  if (!parsed) return [];
  return parsed.options.filter((o): o is FreeformDictOption => o.type === "freeform" && o.kind === "dict" && o.entries.length > 0);
}

/**
 * Story 9.52: what the sub-settings of a dict option accept, when the apworld will not say. Archipelago asks nothing
 * of an `OptionDict`, so most worlds declare nothing and the player faces free text fields; this is where a human
 * fills that gap.
 */
function DictValuesCard({ game, onUpdate }: Props) {
  const [open, setOpen] = useState(false);
  const dictOptions = useMemo(() => dictOptionsOf(game), [game]);
  if (dictOptions.length === 0) return null;

  return (
    <TabCard
      actions={
        <button aria-controls="apworld-dict-values" aria-expanded={open} className={buttonVariants()} onClick={() => setOpen((o) => !o)} type="button">
          {open ? "Replier" : "Ouvrir"}
        </button>
      }
      description={
        <span className="inline-flex flex-wrap items-center gap-1.5">
          {dictOptions.length} {dictOptions.length > 1 ? "réglages" : "réglage"} dont l&apos;apworld ne dit pas les valeurs possibles.
          <span className="inline-flex" title="Quand l'apworld ne déclare pas ce que ces réglages acceptent, c'est ici qu'on le dit : le joueur obtient une liste au lieu d'un champ libre.">
            <Info aria-hidden className="size-3.5" />
          </span>
        </span>
      }
      labelId="apworld-dict-title"
      title="Valeurs des réglages groupés"
    >
      <div className="grid gap-3" hidden={!open} id="apworld-dict-values">
        {dictOptions.map((option) => (
          <DictOptionCard game={game} key={option.key} onUpdate={onUpdate} option={option} />
        ))}
      </div>
    </TabCard>
  );
}

function DictOptionCard({ game, option, onUpdate }: Props & { option: FreeformDictOption }) {
  const curated = game.dictOptionValues?.[option.key];

  // The form holds values as the admin types them - one line, comma separated - rather than as the array they
  // become. Parsing on every keystroke would fight the person mid-word.
  const [rows, setRows] = useState<Record<string, { text: string; closed: boolean }>>(() =>
    Object.fromEntries(
      option.entries.map((e) => {
        const source = curated?.[e.k] ?? option.entryChoices?.[e.k];
        return [e.k, { text: source?.values.join(", ") ?? "", closed: curated?.[e.k]?.closed ?? false }];
      }),
    ),
  );
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);

  async function persist(values: Record<string, { values: string[]; closed: boolean }>): Promise<void> {
    setSaving(true);
    setMessage(null);
    const result = await saveDictOptionValues(game.id, option.key, values);
    if (result.kind === "saved") {
      onUpdate(result.game);
      setMessage({ kind: "ok", text: "Valeurs enregistrées." });
    } else {
      setMessage({ kind: "error", text: result.message });
    }
    setSaving(false);
  }

  function save(): void {
    const values: Record<string, { values: string[]; closed: boolean }> = {};
    for (const [subKey, row] of Object.entries(rows)) {
      const list = [...new Set(row.text.split(",").map((v) => v.trim()).filter((v) => v !== ""))];
      // A sub-setting left empty is simply not curated - that is how one row is dropped without a delete button.
      if (list.length > 0) values[subKey] = { values: list, closed: row.closed };
    }
    void persist(values);
  }

  function reset(): void {
    setRows(Object.fromEntries(option.entries.map((e) => [e.k, { text: "", closed: false }])));
    void persist({});
  }

  return (
    <div className="grid gap-3 rounded border border-border bg-background p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-mono text-sm font-semibold text-foreground">{option.key}</p>
        <p className="text-xs text-muted-foreground">{curated ? "Valeurs définies ici." : "Rien de défini : ce que l'apworld déclare, ou rien."}</p>
      </div>
      <div className="grid gap-2">
        {option.entries.map((entry) => (
          <div className="grid gap-1 sm:grid-cols-[minmax(0,14rem)_1fr_auto] sm:items-center sm:gap-2" key={entry.id}>
            <span className="font-mono text-xs text-muted-foreground">{entry.k}</span>
            <input
              aria-label={`Valeurs de ${entry.k}`}
              className="min-h-9 w-full rounded border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
              disabled={saving}
              onChange={(e) =>
                setRows((prev) => ({ ...prev, [entry.k]: { text: e.target.value, closed: prev[entry.k]?.closed ?? false } }))
              }
              placeholder="valeurs séparées par des virgules"
              value={rows[entry.k]?.text ?? ""}
            />
            <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <input
                checked={rows[entry.k]?.closed ?? false}
                disabled={saving}
                onChange={(e) => setRows((prev) => ({ ...prev, [entry.k]: { text: prev[entry.k]?.text ?? "", closed: e.target.checked } }))}
                type="checkbox"
              />
              Liste complète
            </label>
          </div>
        ))}
      </div>
      <p className="text-xs text-muted-foreground">
        « Liste complète » retire la sortie « Autre » du menu du joueur. À ne cocher que si aucune autre valeur n&apos;est
        acceptée : une valeur déjà enregistrée reste affichée dans tous les cas.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <button className={buttonVariants({ variant: "primary" })} disabled={saving} onClick={save} type="button">
          Enregistrer
        </button>
        <button className={buttonVariants()} disabled={saving || !curated} onClick={reset} type="button">
          Revenir à l&apos;apworld
        </button>
        {message ? <span className={`text-xs ${message.kind === "ok" ? "text-muted-foreground" : "text-danger"}`}>{message.text}</span> : null}
      </div>
    </div>
  );
}
