"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { YamlOptionEditor, type YamlEditorHandle } from "@/features/events/yaml-option-editor";
import type { OptionTypesMap } from "@/lib/archipelago-yaml";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { AdminGamePicker } from "./admin-game-picker";
import {
  ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY,
  ADMIN_WEEKLY_GAMES_QUERY_KEY,
  createAdminWeeklyTemplate,
  fetchAdminGameOptionDetail,
  fetchAdminWeeklyTemplate,
  updateAdminWeeklyTemplate,
} from "./admin-weekly-runs-api";
import type { AdminGameOption, CreateTemplateResult } from "./admin-weekly-runs-api";

type Props = {
  mode: "create" | "edit";
  templateId?: string;
  initialGameId?: string;
};

const FALLBACK_YAML = "name: ArchiLAN\ngame: Archipelago\n";

export function AdminWeeklyTemplateForm({ mode, templateId, initialGameId }: Props) {
  const router = useRouter();
  const queryClient = useQueryClient();

  const [selectedGame, setSelectedGame] = useState<AdminGameOption | null>(null);
  const [hydrated, setHydrated] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [gameId, setGameId] = useState("");
  const [name, setName] = useState("");
  const [defaultYaml, setDefaultYaml] = useState(FALLBACK_YAML);
  const [optionTypes, setOptionTypes] = useState<OptionTypesMap | null>(null);
  const [locationNames, setLocationNames] = useState<string[] | null>(null);
  const [yamlConfig, setYamlConfig] = useState(FALLBACK_YAML);
  const [initialTemplateYaml, setInitialTemplateYaml] = useState<string | null>(null);
  const [maxAttempts, setMaxAttempts] = useState<string>("");
  const [yamlEditorKey, setYamlEditorKey] = useState(0);
  const yamlEditorRef = useRef<YamlEditorHandle>(null);

  // Both fetch fns return null on failure (never throw), so the queries never error and - like
  // the old effect - never retry. In edit mode the game-detail query chains on the template's
  // gameId; in create mode it only runs for a pre-selected game (enabled gating per mode).
  const templateQuery = useQuery({
    queryKey: ["admin-weekly-template", templateId],
    queryFn: () => fetchAdminWeeklyTemplate(templateId!),
    enabled: mode === "edit" && Boolean(templateId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const templateResult = templateQuery.data;
  const template = templateResult ?? null;

  const detailGameId = mode === "edit" ? template?.gameId ?? null : initialGameId ?? null;
  const detailQuery = useQuery({
    queryKey: ["admin-game-option-detail", detailGameId],
    queryFn: () => fetchAdminGameOptionDetail(detailGameId!),
    enabled: detailGameId !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const gameDetailResult = detailQuery.data;

  // One-shot seed of the form from the fetched template + game detail, guarded so background
  // refetches never clobber in-progress edits.
  useEffect(() => {
    if (hydrated) return;

    /* eslint-disable react-hooks/set-state-in-effect -- sanctioned one-shot seed-into-form hydration (guarded by hydrated); the edited template draft is local UI state (AC-ST2), not query state */
    if (mode === "edit" && templateId) {
      if (templateResult === undefined) return;
      if (templateResult === null) {
        setError("Template introuvable.");
      } else {
        if (gameDetailResult === undefined) return;
        setGameId(templateResult.gameId);
        setName(templateResult.name ?? "");
        setYamlConfig(templateResult.yamlConfig);
        setInitialTemplateYaml(templateResult.yamlConfig);
        setMaxAttempts(templateResult.maxAttempts != null ? String(templateResult.maxAttempts) : "");
        setDefaultYaml(gameDetailResult?.defaultYaml || templateResult.yamlConfig || FALLBACK_YAML);
        setOptionTypes(gameDetailResult?.optionTypes ?? null);
        setLocationNames(gameDetailResult?.locationNames ?? null);
      }
    } else if (mode === "create" && initialGameId) {
      // Game pre-selected from the per-game detail page: lock it and load its defaults.
      if (gameDetailResult === undefined) return;
      if (gameDetailResult !== null) {
        setSelectedGame(gameDetailResult);
        setGameId(gameDetailResult.id);
        const nextDefaultYaml = gameDetailResult.defaultYaml || FALLBACK_YAML;
        setDefaultYaml(nextDefaultYaml);
        setYamlConfig(nextDefaultYaml);
        setOptionTypes(gameDetailResult.optionTypes ?? null);
        setLocationNames(gameDetailResult.locationNames ?? null);
      }
    }

    setHydrated(true);
    /* eslint-enable react-hooks/set-state-in-effect */
  }, [hydrated, mode, templateId, initialGameId, templateResult, gameDetailResult]);

  const loading = !hydrated;

  async function handleGameSelect(game: AdminGameOption) {
    setSelectedGame(game);
    setGameId(game.id);
    setInitialTemplateYaml(null);

    const gameDetail = await fetchAdminGameOptionDetail(game.id);
    const nextDefaultYaml = gameDetail?.defaultYaml || FALLBACK_YAML;
    setDefaultYaml(nextDefaultYaml);
    setOptionTypes(gameDetail?.optionTypes ?? null);
    setLocationNames(gameDetail?.locationNames ?? null);
    setYamlConfig(nextDefaultYaml);
    setYamlEditorKey((k) => k + 1);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!gameId) {
      setError("Veuillez sélectionner un jeu.");
      return;
    }
    if (!yamlConfig.trim()) {
      setError("La configuration YAML est obligatoire.");
      return;
    }
    // Same save-time guards as the player slot editor (zero-weight + range bounds): the
    // editor shows the inline banners / red labels; we just gate the submit. (Story 4.16.)
    if (yamlEditorRef.current && !yamlEditorRef.current.validate()) {
      setError("La configuration YAML comporte des erreurs. Corrigez-les avant d'enregistrer.");
      return;
    }

    const parsedMax = maxAttempts.trim() === "" ? null : parseInt(maxAttempts, 10);
    if (maxAttempts.trim() !== "" && (isNaN(parsedMax ?? NaN) || (parsedMax ?? 0) <= 0)) {
      setError("Le nombre de tentatives doit être un entier positif.");
      return;
    }

    setSubmitting(true);

    if (mode === "create") {
      const result: CreateTemplateResult = await createAdminWeeklyTemplate({
        gameId,
        yamlConfig,
        name: name.trim() || null,
        maxAttempts: parsedMax,
      });
      setSubmitting(false);
      if (!result.ok) {
        if (result.error === "game_not_ready") {
          setError("Ce jeu n'a pas encore d'APWorld configuré. Configurez-le d'abord dans la bibliothèque.");
        } else {
          setError("Erreur lors de la création du template.");
        }
        return;
      }
      await queryClient.invalidateQueries({ queryKey: ADMIN_WEEKLY_GAMES_QUERY_KEY });
      await queryClient.invalidateQueries({ queryKey: ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY });
      router.push("/admin/weekly-runs");
    } else if (template) {
      const result = await updateAdminWeeklyTemplate(template.id, {
        name: name.trim() || null,
        yamlConfig,
        maxAttempts: parsedMax,
      });
      setSubmitting(false);
      if (!result) {
        setError("Erreur lors de la mise à jour du template.");
        return;
      }
      await queryClient.invalidateQueries({ queryKey: ADMIN_WEEKLY_GAMES_QUERY_KEY });
      await queryClient.invalidateQueries({ queryKey: ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY });
      router.push("/admin/weekly-runs");
    } else {
      setSubmitting(false);
    }
  }

  if (loading) {
    return (
      <div className="p-8 text-sm text-muted-foreground">Chargement…</div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl p-6 md:p-8">
      <h1 className="font-heading text-xl font-bold text-foreground">
        {mode === "create" ? "Nouveau template hebdomadaire" : "Modifier le template"}
      </h1>

      <form className="mt-6 flex flex-col gap-5" onSubmit={(e) => void handleSubmit(e)}>
        {/* Game selector */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium text-foreground" htmlFor="game-select">
            Jeu <span aria-hidden="true" className="text-danger">*</span>
          </label>
          {mode === "edit" || initialGameId ? (
            <>
              <p
                className="rounded border border-border bg-surface px-3 py-2 text-sm text-foreground opacity-60"
                id="game-select"
              >
                {mode === "edit" ? template?.gameName ?? "-" : selectedGame?.name ?? "-"}
              </p>
              <p className="text-xs text-muted-foreground">
                {mode === "edit"
                  ? "Le jeu ne peut pas être modifié après création."
                  : "Jeu pré-sélectionné depuis sa page de runs."}
              </p>
            </>
          ) : (
            <AdminGamePicker
              id="game-select"
              onSelect={(game) => void handleGameSelect(game)}
              value={selectedGame}
            />
          )}
        </div>

        {/* Template name */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium text-foreground" htmlFor="template-name">
            Nom du template <span className="text-muted-foreground">(optionnel)</span>
          </label>
          <input
            className="rounded border border-border bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"
            id="template-name"
            maxLength={100}
            onChange={(e) => setName(e.target.value)}
            placeholder="Ex. : Wind Waker Full"
            type="text"
            value={name}
          />
        </div>

        {/* Max attempts */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium text-foreground" htmlFor="max-attempts">
            Tentatives max <span className="text-muted-foreground">(vide = illimité)</span>
          </label>
          <input
            className="w-32 rounded border border-border bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"
            id="max-attempts"
            min={1}
            onChange={(e) => setMaxAttempts(e.target.value)}
            placeholder="3"
            type="number"
            value={maxAttempts}
          />
        </div>

        {/* YAML editor */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium text-foreground">Configuration YAML</label>
          <YamlOptionEditor
            key={yamlEditorKey}
            ref={yamlEditorRef}
            defaultYaml={defaultYaml}
            locationNames={locationNames}
            optionTypes={optionTypes}
            playerYaml={initialTemplateYaml}
            onChange={setYamlConfig}
          />
        </div>

        {error && (
          <p className="rounded border border-danger bg-danger/10 px-4 py-3 text-sm text-danger">
            {error}
          </p>
        )}

        <div className="flex gap-3">
          <button
            className="rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? "Enregistrement…" : mode === "create" ? "Créer" : "Enregistrer"}
          </button>
          <button
            className="rounded border border-border px-5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-surface-2"
            onClick={() => router.push("/admin/weekly-runs")}
            type="button"
          >
            Annuler
          </button>
        </div>
      </form>
    </div>
  );
}
