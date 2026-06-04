"use client";

import {ArrowLeft, Info, ShieldAlert} from "lucide-react";
import Link from "next/link";
import type {FormEvent} from "react";
import {useEffect, useRef, useState} from "react";

import {IgdbGameSearch, type IgdbResult} from "@/features/admin/igdb-game-search";
import {apiFetch} from "@/lib/apiFetch";
import {env} from "@/lib/env";

// ─── Types ─────────────────────────────────────────────────────────────────────

type GameAvailability = "available" | "unavailable" | "experimental";

type AdminGame = {
    id: string;
    name: string;
    slug: string;
    description: string;
    coverImageUrl: string | null;
    coverImageAlt: string;
    coverImageCredit: string;
    availability: GameAvailability;
    archipelagoGameName: string | null;
    isYamlReady: boolean;
    isApworldReady: boolean;
    apworldHash: string | null;
    apworldUploadedAt: string | null;
    defaultYaml: string | null;
    catalogSheetName: string | null;
    apworldSourceUrl: string | null;
    apworldDeployedVersion: string | null;
    apworldLatestVersion: string | null;
    apworldCheckedAt: string | null;
    apworldReleaseUrl: string | null;
    availabilityLocked: boolean;
    igdbId: number | null;
    updateStatus: "update_available" | "up_to_date" | "unknown" | "not_tracked";
};

type LoadState =
    | { kind: "loading" }
    | { kind: "ready"; game: AdminGame }
    | { kind: "denied" }
    | { kind: "not_found" }
    | { kind: "error" };

type GithubAsset = { name: string; downloadUrl: string; size: number };

// ─── Root component ────────────────────────────────────────────────────────────

export function AdminGameEditor({gameId}: { gameId: string }) {
    const [loadState, setLoadState] = useState<LoadState>({kind: "loading"});

    function refreshGame(updated: AdminGame) {
        setLoadState({kind: "ready", game: updated});
    }

    useEffect(() => {
        let cancelled = false;

        async function load() {
            try {
                const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`);
                if (cancelled) return;

                if (res.status === 401 || res.status === 403) {
                    setLoadState({kind: "denied"});
                    return;
                }
                if (res.status === 404) {
                    setLoadState({kind: "not_found"});
                    return;
                }
                if (!res.ok) {
                    setLoadState({kind: "error"});
                    return;
                }

                const payload: unknown = await res.json();
                const game = isGamePayload(payload) ? payload.data : null;
                setLoadState(game ? {kind: "ready", game} : {kind: "error"});
            } catch {
                if (!cancelled) setLoadState({kind: "error"});
            }
        }

        void load();
        return () => {
            cancelled = true;
        };
    }, [gameId]);

    if (loadState.kind === "loading") {
        return (
            <div className="mx-auto max-w-5xl px-4 py-10">
                <div className="grid gap-4 border border-border bg-surface p-6">
                    <div className="h-5 w-48 bg-surface-2"/>
                    <div className="h-11 bg-surface-2"/>
                    <div className="h-11 bg-surface-2"/>
                </div>
            </div>
        );
    }

    if (loadState.kind === "denied") {
        return (
            <div className="grid justify-items-center gap-3 p-8 text-center">
                <ShieldAlert className="size-8 text-danger"/>
                <p className="text-foreground">Accès admin requis.</p>
            </div>
        );
    }

    if (loadState.kind === "not_found" || loadState.kind === "error") {
        return <p className="p-8 text-muted-foreground">Jeu introuvable.</p>;
    }

    const {game} = loadState;

    return (
        <div className="mx-auto max-w-5xl px-4 py-10">
            <header className="mb-8 grid gap-3">
                <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
                <div className="flex items-center justify-between gap-4">
                    <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">{game.name}</h1>
                    <Link
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                        href="/admin/jeux"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4"/>
                        Retour
                    </Link>
                </div>
                <p className="font-mono text-sm text-muted-foreground">{game.slug}</p>
            </header>

            <div className="grid gap-6">
                <BasicInfoSection game={game} onUpdate={refreshGame}/>

                <CatalogSyncSection game={game} onUpdate={refreshGame}/>

                <ApworldSection game={game} onUpdate={refreshGame}/>
            </div>
        </div>
    );
}

// ─── Section 1: Informations générales ────────────────────────────────────────

type BasicInfoErrors = Partial<Record<
    "name" | "slug" | "description" | "coverImageUrl" | "coverImageAlt" | "coverImageCredit" | "availability",
    string
>>;

type BasicInfoFields = {
    name: string;
    slug: string;
    description: string;
    coverImageUrl: string;
    coverImageAlt: string;
    coverImageCredit: string;
    availability: GameAvailability;
};

function BasicInfoSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {
    const [errors, setErrors] = useState<BasicInfoErrors>({});
    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess] = useState(false);
    const [fields, setFields] = useState<BasicInfoFields>({
        name: game.name,
        slug: game.slug,
        description: game.description,
        coverImageUrl: game.coverImageUrl ?? "",
        coverImageAlt: game.coverImageAlt,
        coverImageCredit: game.coverImageCredit,
        availability: game.availability,
    });

    function setField<K extends keyof BasicInfoFields>(key: K, value: BasicInfoFields[K]) {
        setFields((f) => ({...f, [key]: value}));
    }

    function handleIgdbSelect(result: IgdbResult) {
        setFields((f) => ({
            ...f,
            name: result.name,
            description: result.summary ? result.summary.slice(0, 500) : f.description,
            coverImageUrl: result.coverUrl ?? f.coverImageUrl,
            coverImageCredit: "© IGDB",
        }));
    }

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setErrors({});
        setSuccess(false);
        setSubmitting(true);

        const input = {
            name: fields.name,
            slug: fields.slug,
            description: fields.description,
            coverImageUrl: fields.coverImageUrl || null,
            coverImageAlt: fields.coverImageAlt,
            coverImageCredit: fields.coverImageCredit,
            availability: fields.availability,
        };

        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}`, {
                body: JSON.stringify(input),
                headers: {"Content-Type": "application/json"},
                method: "PATCH",
            });

            const payload: unknown = await res.json();

            if (!res.ok) {
                setErrors(fieldErrorsFromPayload(payload));
                return;
            }

            if (isGamePayload(payload)) {
                const updated = payload.data;
                onUpdate(updated);
                setFields({
                    name: updated.name,
                    slug: updated.slug,
                    description: updated.description,
                    coverImageUrl: updated.coverImageUrl ?? "",
                    coverImageAlt: updated.coverImageAlt,
                    coverImageCredit: updated.coverImageCredit,
                    availability: updated.availability,
                });
                setSuccess(true);
            }
        } catch {
            setErrors({name: "Impossible de contacter le serveur."});
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Section title="Informations générales">
            <div className="mb-5">
                <p className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-foreground">
                    Importer depuis IGDB
                    <FieldTooltip text="Optionnel - les champs restent modifiables après import."/>
                </p>
                <IgdbGameSearch onSelect={handleIgdbSelect}/>
            </div>

            <form className="grid gap-4" onSubmit={submit}>
                <div className="grid items-start gap-4 md:grid-cols-2">
                    <TextField
                        error={errors.name}
                        label="Nom"
                        name="name"
                        value={fields.name}
                        onChange={(v) => setField("name", v)}
                    />
                    <TextField
                        error={errors.slug}
                        hint="Identifiant technique. Modifier le slug casse les configurations de sélection existantes."
                        label="Slug"
                        name="slug"
                        value={fields.slug}
                        onChange={(v) => setField("slug", v)}
                    />
                    <TextField
                        error={errors.coverImageUrl}
                        hint="URL publique de l'image de couverture (HTTPS)."
                        label="URL de la couverture"
                        name="coverImageUrl"
                        placeholder="https://images.igdb.com/…"
                        value={fields.coverImageUrl}
                        onChange={(v) => setField("coverImageUrl", v)}
                    />
                    <TextField
                        error={errors.coverImageAlt}
                        hint="Optionnel. Description pour les lecteurs d'écran et le référencement."
                        label="Texte alternatif de la couverture"
                        name="coverImageAlt"
                        value={fields.coverImageAlt}
                        onChange={(v) => setField("coverImageAlt", v)}
                    />
                    <TextField
                        error={errors.coverImageCredit}
                        hint="Auteur ou source de l'image (ex : © Nintendo, © IGDB)."
                        label="Crédit image de couverture"
                        name="coverImageCredit"
                        value={fields.coverImageCredit}
                        onChange={(v) => setField("coverImageCredit", v)}
                    />
                    <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                        Disponibilité
                        <select
                            className={`min-h-11 rounded border bg-background px-3 text-foreground outline-none focus:border-accent ${errors.availability ? "border-danger" : "border-border"}`}
                            name="availability"
                            value={fields.availability}
                            onChange={(e) => setField("availability", e.target.value as GameAvailability)}
                        >
                            <option value="available">Disponible</option>
                            <option value="experimental">Expérimental</option>
                            <option value="unavailable">Indisponible</option>
                        </select>
                        {errors.availability ?
                            <span className="text-xs text-danger" role="alert">{errors.availability}</span> : null}
                    </label>
                </div>

                <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                    Description
                    <textarea
                        className={`min-h-28 rounded border bg-background px-3 py-2 outline-none focus:border-accent ${errors.description ? "border-danger" : "border-border"}`}
                        name="description"
                        value={fields.description}
                        onChange={(e) => setField("description", e.target.value)}
                    />
                    {errors.description ?
                        <span className="text-xs text-danger" role="alert">{errors.description}</span> : null}
                </label>

                <SectionFooter submitting={submitting} success={success} label="Enregistrer"/>
            </form>
        </Section>
    );
}

// ─── Section 2: Catalogue sync ────────────────────────────────────────────────

type CatalogSyncErrors = Partial<Record<"catalogSheetName" | "apworldSourceUrl", string>>;

function CatalogSyncSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {
    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess] = useState(false);
    const [errors, setErrors] = useState<CatalogSyncErrors>({});
    const [fields, setFields] = useState({
        catalogSheetName: game.catalogSheetName ?? "",
        apworldSourceUrl: game.apworldSourceUrl ?? "",
        availabilityLocked: game.availabilityLocked,
    });

    function setField<K extends keyof typeof fields>(key: K, value: (typeof fields)[K]) {
        setFields((f) => ({...f, [key]: value}));
    }

    async function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setErrors({});
        setSuccess(false);
        setSubmitting(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}`, {
                method: "PATCH",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({
                    name: game.name,
                    slug: game.slug,
                    description: game.description,
                    coverImageUrl: game.coverImageUrl,
                    coverImageAlt: game.coverImageAlt,
                    coverImageCredit: game.coverImageCredit,
                    availability: game.availability,
                    catalog_sheet_name: fields.catalogSheetName || null,
                    apworld_source_url: fields.apworldSourceUrl || null,
                    availability_locked: fields.availabilityLocked,
                }),
            });
            const payload: unknown = await res.json();
            if (!res.ok) {
                const details = extractDetails(payload);
                setErrors({
                    catalogSheetName: details["catalogSheetName"]?.[0],
                    apworldSourceUrl: details["apworldSourceUrl"]?.[0],
                });
                return;
            }
            if (isGamePayload(payload)) {
                onUpdate(payload.data);
                setSuccess(true);
            }
        } catch {
            setErrors({catalogSheetName: "Impossible de contacter le serveur."});
        } finally {
            setSubmitting(false);
        }
    }

    const statusLabel: Record<string, string> = {
        up_to_date: "À jour",
        update_available: "Mise à jour disponible",
        unknown: "Version inconnue",
        not_tracked: "Non suivi",
    };

    const statusColor: Record<string, string> = {
        up_to_date: "text-success",
        update_available: "text-warning",
        unknown: "text-muted-foreground",
        not_tracked: "text-muted-foreground",
    };

    return (
        <Section title="Catalogue & APWorld source">
            {game.updateStatus !== "not_tracked" && (
                <div className="mb-5 flex flex-wrap gap-4 rounded border border-border bg-surface-2 px-4 py-3 text-sm">
          <span className={`font-semibold ${statusColor[game.updateStatus] ?? "text-muted-foreground"}`}>
            {statusLabel[game.updateStatus] ?? game.updateStatus}
          </span>
                    {game.apworldDeployedVersion && (
                        <span className="text-muted-foreground">Déployé : <span
                            className="font-mono">{game.apworldDeployedVersion}</span></span>
                    )}
                    {game.apworldLatestVersion && (
                        <span className="text-muted-foreground">Dernier : <span
                            className="font-mono">{game.apworldLatestVersion}</span></span>
                    )}
                    {game.apworldReleaseUrl && (
                        <a
                            className="inline-flex items-center gap-1 text-accent-text hover:underline"
                            href={game.apworldReleaseUrl}
                            rel="noopener"
                            target="_blank"
                        >
                            Release GitHub
                        </a>
                    )}
                    {game.apworldCheckedAt && (
                        <span className="text-xs text-muted-foreground">
              Vérifié le {new Date(game.apworldCheckedAt).toLocaleString("fr-FR")}
            </span>
                    )}
                </div>
            )}

            <form className="grid gap-4" onSubmit={submit}>
                <div className="grid gap-4 md:grid-cols-2">
                    <TextField
                        error={errors.catalogSheetName}
                        hint="Doit correspondre exactement au nom dans le Google Sheet pour la synchronisation automatique."
                        label="Nom dans le sheet (catalog_sheet_name)"
                        name="catalogSheetName"
                        value={fields.catalogSheetName}
                        onChange={(v) => setField("catalogSheetName", v)}
                    />
                    <TextField
                        error={errors.apworldSourceUrl}
                        hint="https://github.com/{owner}/{repo} - utilisé pour vérifier les mises à jour."
                        label="URL source APWorld (GitHub)"
                        name="apworldSourceUrl"
                        placeholder="https://github.com/owner/repo"
                        value={fields.apworldSourceUrl}
                        onChange={(v) => setField("apworldSourceUrl", v)}
                    />
                </div>
                <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-foreground">
                    <input
                        checked={fields.availabilityLocked}
                        className="size-4 rounded border-border accent-accent"
                        type="checkbox"
                        onChange={(e) => setField("availabilityLocked", e.target.checked)}
                    />
                    Disponibilité verrouillée (ne pas écraser depuis le sheet)
                </label>
                <SectionFooter label="Enregistrer" submitting={submitting} success={success}/>
            </form>
        </Section>
    );
}

// ─── Section 3: Fichier .apworld ───────────────────────────────────────────────

function ApworldSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {

    const [uploadError, setUploadError] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const [loadingAssets, setLoadingAssets] = useState(false);
    const [importingGithub, setImportingGithub] = useState(false);
    const [githubAssets, setGithubAssets] = useState<GithubAsset[] | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    async function handleOpenGithubPicker() {
        setUploadError(null);
        setGithubAssets(null);
        setLoadingAssets(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/github-assets`);
            const payload: unknown = await res.json();
            if (!res.ok) {
                setUploadError(extractErrorMessage(payload) ?? "Erreur lors de la récupération des assets.");
                return;
            }
            const assets = isGithubAssetsPayload(payload) ? payload.data : [];
            if (assets.length === 1) {
                // Only one asset - import directly without showing picker
                await doImport(assets[0].downloadUrl, assets[0].name);
            } else {
                setGithubAssets(assets);
            }
        } catch {
            setUploadError("Impossible de contacter le serveur.");
        } finally {
            setLoadingAssets(false);
        }
    }

    async function doImport(downloadUrl: string, assetName: string) {
        setUploadError(null);
        setImportingGithub(true);
        setGithubAssets(null);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/apworld-from-github`, {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({assetDownloadUrl: downloadUrl, assetName}),
            });
            const payload: unknown = await res.json();
            if (!res.ok) {
                setUploadError(extractErrorMessage(payload) ?? "Erreur lors de l'import.");
                return;
            }
            if (isGamePayload(payload)) onUpdate(payload.data);
        } catch {
            setUploadError("Impossible de contacter le serveur.");
        } finally {
            setImportingGithub(false);
        }
    }

    async function handleUpload() {
        if (!selectedFile) return;
        setUploadError(null);
        setUploading(true);

        try {
            const formData = new FormData();
            formData.append("file", selectedFile);

            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/apworld`, {
                body: formData,
                method: "PATCH",
            });

            const payload: unknown = await res.json();

            if (!res.ok) {
                const details = extractDetails(payload);
                setUploadError(details["file"]?.[0] ?? "Une erreur est survenue.");
                return;
            }

            if (isGamePayload(payload)) {
                onUpdate(payload.data);
                setSelectedFile(null);
                if (fileInputRef.current) fileInputRef.current.value = "";
            }
        } catch {
            setUploadError("Impossible de contacter le serveur.");
        } finally {
            setUploading(false);
        }
    }

    return (
        <Section
            description="Fichier .apworld pour les joueurs configurant leur slot avec un YAML personnalisé."
            title="Fichier .apworld"
        >
            {game.isApworldReady ? (
                <div className="grid gap-1">
                    <p className="text-sm text-success">
                        Configuré le{" "}
                        {game.apworldUploadedAt
                            ? new Date(game.apworldUploadedAt).toLocaleDateString("fr-FR", {
                                day: "numeric", month: "long", year: "numeric",
                                hour: "2-digit", minute: "2-digit",
                            })
                            : "-"}{" "}
                        - SHA-256 : {game.apworldHash ? `${game.apworldHash.slice(0, 8)}…` : "-"}
                    </p>
                    <p className="font-mono text-xs text-muted-foreground">
                        Nom Archipelago :{" "}
                        {game.archipelagoGameName
                            ? <span className="text-foreground">{game.archipelagoGameName}</span>
                            : <span className="text-danger">manquant</span>}
                    </p>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">Aucun fichier .apworld configuré.</p>
            )}

            {game.apworldSourceUrl && (
                <div className="mt-4">
                    <button
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={importingGithub || uploading || loadingAssets}
                        type="button"
                        onClick={handleOpenGithubPicker}
                    >
                        {loadingAssets ? "Chargement…" : importingGithub ? "Import en cours…" : "Importer depuis GitHub"}
                    </button>
                    <p className="mt-1 font-mono text-xs text-muted-foreground">{game.apworldSourceUrl}</p>

                    {githubAssets !== null && githubAssets.length === 0 && (
                        <p className="mt-2 text-sm text-danger">Aucun asset .apworld trouvé dans la dernière
                            release.</p>
                    )}

                    {githubAssets !== null && githubAssets.length > 1 && (
                        <div className="mt-3 rounded border border-border bg-surface-2 p-3">
                            <p className="mb-2 text-sm font-semibold text-foreground">
                                Plusieurs .apworld disponibles - choisissez :
                            </p>
                            <ul className="flex flex-col gap-1.5">
                                {githubAssets.map((asset) => (
                                    <li key={asset.downloadUrl}>
                                        <button
                                            className="flex w-full items-center justify-between gap-3 rounded border border-border bg-surface px-3 py-2 text-left text-sm transition-colors hover:border-accent disabled:opacity-60"
                                            disabled={importingGithub}
                                            type="button"
                                            onClick={() => doImport(asset.downloadUrl, asset.name)}
                                        >
                                            <span className="font-mono text-foreground">{asset.name}</span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                        {(asset.size / 1024).toFixed(0)} Ko
                      </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <button
                                className="mt-2 text-xs text-muted-foreground hover:text-foreground"
                                type="button"
                                onClick={() => setGithubAssets(null)}
                            >
                                Annuler
                            </button>
                        </div>
                    )}
                </div>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <input
                    accept=".apworld"
                    className="text-sm text-foreground file:mr-3 file:rounded file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-foreground hover:file:border-accent"
                    ref={fileInputRef}
                    type="file"
                    onChange={(e) => setSelectedFile(e.target.files?.[0] ?? null)}
                />
                <button
                    className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={!selectedFile || uploading}
                    type="button"
                    onClick={handleUpload}
                >
                    {uploading ? "Envoi en cours…" : "Uploader le .apworld"}
                </button>
            </div>

            {uploadError ? <p className="mt-3 text-sm text-danger" role="alert">{uploadError}</p> : null}

            {game.defaultYaml ? (
                <div className="mt-6">
                    <p className="text-sm font-semibold text-foreground">Template YAML extrait</p>
                    <pre
                        className="mt-2 max-h-96 overflow-auto whitespace-pre rounded border border-border bg-background p-3 font-mono text-xs text-muted-foreground">
            {game.defaultYaml}
          </pre>
                </div>
            ) : null}
        </Section>
    );
}

// ─── Shared UI primitives ─────────────────────────────────────────────────────

function Section({
                     children,
                     description,
                     title,
                 }: {
    children: React.ReactNode;
    description?: string;
    title: string;
}) {
    return (
        <section className="min-w-0 rounded-lg border border-border bg-surface p-6">
            <h2 className="font-heading text-xl font-semibold text-foreground">{title}</h2>
            {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
            <div className="mt-5">{children}</div>
        </section>
    );
}

function SectionFooter({
                           label,
                           submitting,
                           success,
                       }: {
    label: string;
    submitting: boolean;
    success: boolean;
}) {
    return (
        <div className="flex items-center gap-4">
            <button
                className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                disabled={submitting}
                type="submit"
            >
                {submitting ? "Enregistrement…" : label}
            </button>
            {success ? <span className="text-sm text-success">Enregistré.</span> : null}
        </div>
    );
}

function TextField({
                       defaultValue,
                       error,
                       hint,
                       label,
                       name,
                       placeholder,
                       value,
                       onChange,
                   }: {
    defaultValue?: string;
    error?: string;
    hint?: string;
    label: string;
    name: string;
    placeholder?: string;
    value?: string;
    onChange?: (value: string) => void;
}) {
    const controlled = value !== undefined && onChange !== undefined;
    return (
        <label className="grid gap-1.5 text-sm font-semibold text-foreground">
      <span className="flex items-center gap-1.5">
        {label}
          {hint ? <FieldTooltip text={hint}/> : null}
      </span>
            <input
                className={`min-h-11 rounded border bg-background px-3 outline-none focus:border-accent ${error ? "border-danger" : "border-border"}`}
                name={name}
                placeholder={placeholder}
                {...(controlled
                    ? {value, onChange: (e) => onChange(e.target.value)}
                    : {defaultValue})}
            />
            {error ? <span className="text-xs text-danger" role="alert">{error}</span> : null}
        </label>
    );
}

function FieldTooltip({text}: { text: string }) {
    return (
        <span className="group relative inline-flex">
      <Info aria-hidden="true" className="size-3.5 cursor-help text-muted-foreground"/>
      <span
          className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-56 -translate-x-1/2 rounded border border-border bg-surface-2 px-2.5 py-2 text-xs font-normal leading-relaxed text-muted-foreground opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
        {text}
      </span>
    </span>
    );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function extractErrorMessage(payload: unknown): string | undefined {
    if (typeof payload !== "object" || payload === null || !("error" in payload)) return undefined;
    const err = (payload as { error: unknown }).error;
    if (typeof err !== "object" || err === null || !("message" in err)) return undefined;
    const msg = (err as { message: unknown }).message;
    return typeof msg === "string" ? msg : undefined;
}

function extractDetails(payload: unknown): Record<string, string[]> {
    if (typeof payload !== "object" || payload === null || !("error" in payload)) return {};
    const err = (payload as { error: unknown }).error;
    if (typeof err !== "object" || err === null || !("details" in err)) return {};
    const d = (err as { details: unknown }).details;
    if (typeof d !== "object" || d === null) return {};
    return d as Record<string, string[]>;
}

function isGithubAssetsPayload(v: unknown): v is { data: GithubAsset[] } {
    if (typeof v !== "object" || v === null || !("data" in v)) return false;
    const data = (v as { data: unknown }).data;
    if (!Array.isArray(data)) return false;
    return data.every(
        (item) => typeof item === "object" && item !== null &&
            "name" in item && typeof (item as { name: unknown }).name === "string" &&
            "downloadUrl" in item && typeof (item as { downloadUrl: unknown }).downloadUrl === "string" &&
            "size" in item && typeof (item as { size: unknown }).size === "number",
    );
}

function fieldErrorsFromPayload(payload: unknown): BasicInfoErrors {
    const details =
        payload && typeof payload === "object" && "error" in payload &&
        typeof (payload as { error: unknown }).error === "object"
            ? ((payload as { error: { details?: unknown } }).error.details ?? {})
            : {};

    function first(key: string): string | undefined {
        if (!details || typeof details !== "object") return undefined;
        const v = (details as Record<string, unknown>)[key];
        return Array.isArray(v) && typeof v[0] === "string" ? v[0] : undefined;
    }

    return {
        name: first("name"),
        slug: first("slug"),
        description: first("description"),
        coverImageUrl: first("coverImageUrl"),
        coverImageAlt: first("coverImageAlt"),
        coverImageCredit: first("coverImageCredit"),
        availability: first("availability"),
    };
}

function isGamePayload(payload: unknown): payload is { data: AdminGame } {
    const data = payload && typeof payload === "object" && "data" in payload
        ? (payload as { data: unknown }).data
        : null;
    return Boolean(data && typeof data === "object" && "id" in data && "name" in data);
}
