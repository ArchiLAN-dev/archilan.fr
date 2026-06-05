"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { YamlOptionEditor } from "@/features/events/yaml-option-editor";
import { AdminGamePicker } from "./admin-game-picker";
import {
  createAdminWeeklyTemplate,
  fetchAdminGameOptionDetail,
  fetchAdminWeeklyTemplate,
  updateAdminWeeklyTemplate,
} from "./admin-weekly-runs-api";
import type { AdminGameOption, AdminWeeklyTemplate, CreateTemplateResult } from "./admin-weekly-runs-api";

type Props = {
  mode: "create" | "edit";
  templateId?: string;
};

const FALLBACK_YAML = "name: ArchiLAN\ngame: Archipelago\n";

export function AdminWeeklyTemplateForm({ mode, templateId }: Props) {
  const router = useRouter();

  const [selectedGame, setSelectedGame] = useState<AdminGameOption | null>(null);
  const [template, setTemplate] = useState<AdminWeeklyTemplate | null>(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [gameId, setGameId] = useState("");
  const [name, setName] = useState("");
  const [defaultYaml, setDefaultYaml] = useState(FALLBACK_YAML);
  const [yamlConfig, setYamlConfig] = useState(FALLBACK_YAML);
  const [initialTemplateYaml, setInitialTemplateYaml] = useState<string | null>(null);
  const [maxAttempts, setMaxAttempts] = useState<string>("");
  const [yamlEditorKey, setYamlEditorKey] = useState(0);

  useEffect(() => {
    async function load() {
      if (mode === "edit" && templateId) {
        const tmpl = await fetchAdminWeeklyTemplate(templateId);
        if (!tmpl) {
          setError("Template introuvable.");
          setLoading(false);
          return;
        }
        setTemplate(tmpl);
        setGameId(tmpl.gameId);
        setName(tmpl.name ?? "");
        setYamlConfig(tmpl.yamlConfig);
        setInitialTemplateYaml(tmpl.yamlConfig);
        setMaxAttempts(tmpl.maxAttempts != null ? String(tmpl.maxAttempts) : "");

        const gameDetail = await fetchAdminGameOptionDetail(tmpl.gameId);
        setDefaultYaml(gameDetail?.defaultYaml || tmpl.yamlConfig || FALLBACK_YAML);
      }

      setLoading(false);
    }

    void load();
  }, [mode, templateId]);

  async function handleGameSelect(game: AdminGameOption) {
    setSelectedGame(game);
    setGameId(game.id);
    setInitialTemplateYaml(null);

    const gameDetail = await fetchAdminGameOptionDetail(game.id);
    const nextDefaultYaml = gameDetail?.defaultYaml || FALLBACK_YAML;
    setDefaultYaml(nextDefaultYaml);
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
          {mode === "edit" ? (
            <>
              <p
                className="rounded border border-border bg-surface px-3 py-2 text-sm text-foreground opacity-60"
                id="game-select"
              >
                {template?.gameName ?? "—"}
              </p>
              <p className="text-xs text-muted-foreground">
                Le jeu ne peut pas être modifié après création.
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
            defaultYaml={defaultYaml}
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
