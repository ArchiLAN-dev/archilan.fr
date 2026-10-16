"use client";

import {ArrowLeft, Info, MessageSquarePlus, RefreshCw, ShieldAlert, StickyNote, type LucideIcon} from "lucide-react";
import Link from "next/link";
import type {FormEvent} from "react";
import {useState} from "react";
import {useQuery, useQueryClient} from "@tanstack/react-query";

import {
    fetchAdminGame,
    isAdminGamePayload as isGamePayload,
    savePlatforms,
    type AdminGame,
    type AdminGameResult,
    type GameAvailability,
} from "@/features/admin/admin-games-api";
import {IgdbGameSearch, type IgdbResult} from "@/features/admin/igdb-game-search";
import {InstallStepsEditor, serializeStepsForSave, type InstallStep} from "@/features/games/install-steps-editor";
import {MarkdownEditor} from "@/components/markdown/markdown-editor";
import {GAME_DESCRIPTION_MAX} from "@/lib/content-limits";
import {apiFetch} from "@/lib/apiFetch";
import {env} from "@/lib/env";
import {DEFAULT_STALE_TIME} from "@/lib/query-client";
import {APWORLD_INCIDENTS_QUERY_KEY, fetchApworldIncidents} from "./admin-apworld-health-api";
import {DEFAULT_CONTRIBUTION_FILTERS, fetchContributionQueue, type ContributionItem} from "./admin-game-contributions-api";
import {contributionFiltersToParams} from "./moderation-filters";
import {ApworldIncidentBanner} from "./apworld-incident-banner";
import {ApworldTab} from "./admin-game-apworld-tab";
import {updateStatusLabel, updateStatusTone, type ApworldUpdateStatusTone} from "./apworld-update-status";

// ─── Types ─────────────────────────────────────────────────────────────────────

type LoadState = { kind: "loading" } | AdminGameResult;

// ─── Root component ────────────────────────────────────────────────────────────

const EDITOR_TABS = [
    {id: "general", label: "Général"},
    {id: "catalogue", label: "Catalogue"},
    {id: "apworld", label: "APWorld"},
    {id: "tutoriel", label: "Tutoriel"},
    {id: "notes", label: "Notes"},
] as const;

type EditorTab = (typeof EDITOR_TABS)[number]["id"];

/** Story 11.7: whether the game carries an internal note worth reading - blank space is not a note. */
export function hasAdminNote(notes: string | null): boolean {
    return (notes ?? "").trim() !== "";
}

/**
 * What makes a tab stand out (story 11.7): its icon, what it says to a screen reader, and a count when there are
 * several things waiting (else a dot).
 */
export type TabFlag = {icon: LucideIcon; hint: string; count?: number};

/**
 * Story 11.7: the apworld needs the admin when an incident is open, a new version waits for their go-ahead, or the
 * test generation failed without a waiver.
 */
export function apworldNeedsAttention(game: Pick<AdminGame, "apworldCandidate" | "apworldPreflight">, activeIncidents: number): boolean {
    return activeIncidents > 0
        || game.apworldCandidate?.status === "awaiting"
        || (game.apworldPreflight?.status === "failed" && !game.apworldPreflight.overridden);
}

/** Story 11.7: the flag of each tab, null when it has nothing waiting. */
export function editorTabFlags(
    game: Pick<AdminGame, "adminNotes" | "apworldCandidate" | "apworldPreflight">,
    activeIncidents: number,
    pendingContributions: number,
): Record<EditorTab, TabFlag | null> {
    return {
        general: null,
        catalogue: null,
        apworld: apworldNeedsAttention(game, activeIncidents) ? {icon: ShieldAlert, hint: "à vérifier"} : null,
        tutoriel: pendingContributions > 0
            ? {
                icon: MessageSquarePlus,
                hint: pendingContributions > 1 ? `${pendingContributions} propositions en attente` : "une proposition en attente",
                count: pendingContributions,
            }
            : null,
        notes: hasAdminNote(game.adminNotes) ? {icon: StickyNote, hint: "une note interne existe"} : null,
    };
}

/**
 * One tab of the editor. Story 11.7: a flagged tab - a note to read, an apworld to check, a tutorial proposal to
 * review - stands out even when it is not open (warm colour, its icon, a dot or a count), so the admin does not
 * edit the game without seeing it.
 */
export function EditorTabButton({id, label, active, flag, onSelect}: {
    id: string;
    label: string;
    active: boolean;
    flag: TabFlag | null;
    onSelect: () => void;
}) {
    const tone = active
        ? flag !== null ? "border-accent-warm text-foreground" : "border-accent text-foreground"
        : flag !== null ? "border-accent-warm/40 bg-accent-warm/10 text-accent-warm hover:text-foreground" : "border-transparent text-muted-foreground hover:text-foreground";
    const Icon = flag?.icon;

    return (
        <button
            aria-controls={`panel-${id}`}
            aria-label={flag !== null ? `${label} - ${flag.hint}` : undefined}
            aria-selected={active}
            className={`-mb-px inline-flex min-h-10 items-center gap-1.5 rounded-t border-b-2 px-4 text-sm font-semibold transition-colors ${tone}`}
            id={`tab-${id}`}
            onClick={onSelect}
            role="tab"
            type="button"
        >
            {Icon !== undefined ? <Icon aria-hidden className="size-4"/> : null}
            {label}
            {flag !== null && flag.count !== undefined && flag.count > 1 ? (
                <span aria-hidden className="rounded-full bg-accent-warm px-1.5 text-xs font-bold text-white">{flag.count}</span>
            ) : flag !== null ? (
                <span aria-hidden className="size-2 rounded-full bg-accent-warm"/>
            ) : null}
        </button>
    );
}

const proposalDate = new Intl.DateTimeFormat("fr-FR", {dateStyle: "medium", timeZone: "Europe/Paris"});

/**
 * Story 11.7: the tutorial proposals members made for this game, waiting for review - each one a full tutorial
 * that would replace the current one. They are reviewed in the moderation queue, which opens on this game.
 */
export function PendingTutorialProposals({gameName, proposals}: { gameName: string; proposals: ContributionItem[] }) {
    if (proposals.length === 0) return null;
    const review = `/admin/moderation/contributions?${contributionFiltersToParams({...DEFAULT_CONTRIBUTION_FILTERS, search: gameName}).toString()}`;

    return (
        <section className="mb-6 grid gap-3 rounded-lg border border-accent-warm/40 bg-accent-warm/10 p-4" aria-labelledby="pending-proposals-title">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 font-heading text-base font-semibold text-foreground" id="pending-proposals-title">
                    <MessageSquarePlus aria-hidden className="size-5 text-accent-warm"/>
                    {proposals.length > 1 ? `${proposals.length} propositions de tutoriel en attente` : "Une proposition de tutoriel en attente"}
                </h2>
                <Link className="inline-flex min-h-9 items-center rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover" href={review}>
                    Examiner dans la modération
                </Link>
            </div>
            <p className="text-sm text-muted-foreground">
                Une proposition approuvée remplace le tutoriel ci-dessous : à examiner avant de le retoucher ici.
            </p>
            <ul className="grid gap-2" role="list">
                {proposals.map((proposal) => (
                    <li className="rounded border border-border bg-surface px-3 py-2 text-sm" key={proposal.id}>
                        <p className="text-foreground">
                            <span className="font-semibold">{proposal.authorName}</span>
                            <span className="text-muted-foreground"> · {proposalDate.format(new Date(proposal.createdAt))} · {proposal.proposedSteps.length} étape{proposal.proposedSteps.length > 1 ? "s" : ""}</span>
                        </p>
                        {proposal.message !== null ? <p className="mt-1 text-muted-foreground">« {proposal.message} »</p> : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function AdminGameEditor({gameId}: { gameId: string }) {
    const queryClient = useQueryClient();
    const [activeTab, setActiveTab] = useState<EditorTab>("general");

    // fetchAdminGame never throws (denied/not_found/error are encoded in the result kind), so
    // the query never errors and - like the old effect - never retries.
    const {data} = useQuery({
        queryKey: ["admin-game", gameId],
        queryFn: () => fetchAdminGame(gameId),
        staleTime: DEFAULT_STALE_TIME,
        retry: false,
        // Story 9.38: the apworld test generation runs asynchronously on the orchestrator,
        // so poll while its verdict is pending instead of making the admin reload the page.
        // Story 38.6: same while a new version is in test - the page shows the switch when it happens.
        refetchInterval: (query) => {
            const result = query.state.data;
            return result?.kind === "ready"
                && (result.game.apworldPreflight?.status === "pending" || result.game.apworldCandidate?.status === "testing")
                ? 10_000
                : false;
        },
    });
    const loadState: LoadState = data ?? {kind: "loading"};

    // Story 38.3 AC6: the active incidents of this game's apworld, as a banner. Shares the incident
    // query prefix, so an action on the health page refreshes it.
    const {data: activeIncidents} = useQuery({
        queryKey: [...APWORLD_INCIDENTS_QUERY_KEY, "game", gameId],
        queryFn: () => fetchApworldIncidents("active", gameId),
        staleTime: DEFAULT_STALE_TIME,
    });

    // Story 11.7: the tutorial proposals waiting for this game. Shares the moderation queue's prefix, so approving
    // or rejecting one there refreshes the tab here.
    const {data: contributions} = useQuery({
        queryKey: ["admin-game-contributions", "game", gameId],
        queryFn: () => fetchContributionQueue({...DEFAULT_CONTRIBUTION_FILTERS, gameId}),
        staleTime: DEFAULT_STALE_TIME,
    });

    // Mutation handlers push the PATCH/POST response straight into the cache: no refetch, the
    // sections keep their no-flash update semantics.
    function refreshGame(updated: AdminGame) {
        const next: AdminGameResult = {kind: "ready", game: updated};
        queryClient.setQueryData(["admin-game", gameId], next);
    }

    if (loadState.kind === "loading") {
        return (
            <div className="mx-auto max-w-content px-4 py-10">
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
    const pendingContributions = contributions?.items ?? [];
    const tabFlags = editorTabFlags(game, activeIncidents?.length ?? 0, pendingContributions.length);

    return (
        <div className="mx-auto max-w-content px-4 py-10">
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

            <ApworldIncidentBanner incidents={activeIncidents ?? []}/>

            <div
                aria-label="Sections de configuration du jeu"
                className="mb-6 flex flex-wrap gap-2 border-b border-border"
                role="tablist"
            >
                {EDITOR_TABS.map((tab) => (
                    <EditorTabButton
                        active={activeTab === tab.id}
                        flag={tabFlags[tab.id]}
                        id={tab.id}
                        key={tab.id}
                        label={tab.label}
                        onSelect={() => setActiveTab(tab.id)}
                    />
                ))}
            </div>

            {/* All panels stay mounted (hidden when inactive) so unsaved edits survive tab switches. */}
            <div aria-labelledby="tab-general" hidden={activeTab !== "general"} id="panel-general" role="tabpanel">
                <BasicInfoSection game={game} onUpdate={refreshGame}/>
            </div>
            <div aria-labelledby="tab-catalogue" hidden={activeTab !== "catalogue"} id="panel-catalogue" role="tabpanel">
                <CatalogSyncSection game={game} onUpdate={refreshGame}/>
            </div>
            <div aria-labelledby="tab-apworld" hidden={activeTab !== "apworld"} id="panel-apworld" role="tabpanel">
                <ApworldTab game={game} onUpdate={refreshGame}/>
            </div>
            <div aria-labelledby="tab-tutoriel" hidden={activeTab !== "tutoriel"} id="panel-tutoriel" role="tabpanel">
                <PendingTutorialProposals gameName={game.name} proposals={pendingContributions}/>
                <InstallTutorialSection game={game} onUpdate={refreshGame}/>
            </div>
            <div aria-labelledby="tab-notes" hidden={activeTab !== "notes"} id="panel-notes" role="tabpanel">
                <NotesSection game={game} onUpdate={refreshGame}/>
            </div>
        </div>
    );
}

// ─── Section 1: Informations générales ────────────────────────────────────────

type BasicInfoErrors = Partial<Record<
    "name" | "slug" | "description" | "coverImageUrl" | "coverImageAlt" | "coverImageCredit" | "availability"
    | "disabledMessage",
    string
>>;

type BasicInfoFields = {
    name: string;
    slug: string;
    description: string;
    archipelagoDescription: string;
    coverImageUrl: string;
    coverImageAlt: string;
    coverImageCredit: string;
    availability: GameAvailability;
    igdbId: number | null;
    disabled: boolean;
    disabledMessage: string;
};

function BasicInfoSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {
    const [errors, setErrors] = useState<BasicInfoErrors>({});
    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess] = useState(false);
    const [resyncing, setResyncing] = useState(false);
    const [platformsMessage, setPlatformsMessage] = useState<string | null>(null);
    const [fields, setFields] = useState<BasicInfoFields>({
        name: game.name,
        slug: game.slug,
        description: game.description,
        archipelagoDescription: game.archipelagoDescription ?? "",
        coverImageUrl: game.coverImageUrl ?? "",
        coverImageAlt: game.coverImageAlt,
        coverImageCredit: game.coverImageCredit,
        availability: game.availability,
        igdbId: game.igdbId,
        disabled: game.disabled,
        disabledMessage: game.disabledMessage ?? "",
    });

    function setField<K extends keyof BasicInfoFields>(key: K, value: BasicInfoFields[K]) {
        setFields((f) => ({...f, [key]: value}));
    }

    function handleIgdbSelect(result: IgdbResult) {
        setFields((f) => ({
            ...f,
            name: result.name,
            description: result.summary ?? f.description,
            coverImageUrl: result.coverUrl ?? f.coverImageUrl,
            coverImageCredit: "© IGDB",
            igdbId: result.igdbId,
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
            archipelagoDescription: fields.archipelagoDescription || null,
            coverImageUrl: fields.coverImageUrl || null,
            coverImageAlt: fields.coverImageAlt,
            coverImageCredit: fields.coverImageCredit,
            availability: fields.availability,
            igdb_id: fields.igdbId,
            disabled: fields.disabled,
            disabledMessage: fields.disabledMessage || null,
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
                    archipelagoDescription: updated.archipelagoDescription ?? "",
                    coverImageUrl: updated.coverImageUrl ?? "",
                    coverImageAlt: updated.coverImageAlt,
                    coverImageCredit: updated.coverImageCredit,
                    availability: updated.availability,
                    igdbId: updated.igdbId,
                    disabled: updated.disabled,
                    disabledMessage: updated.disabledMessage ?? "",
                });
                setSuccess(true);
            }
        } catch {
            setErrors({name: "Impossible de contacter le serveur."});
        } finally {
            setSubmitting(false);
        }
    }

    async function resyncPlatforms() {
        setPlatformsMessage(null);
        setResyncing(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/resync-platforms`, {
                method: "POST",
            });
            const payload: unknown = await res.json();
            if (!res.ok) {
                setPlatformsMessage(
                    extractDetails(payload)["platforms"]?.[0] ?? "Échec de la synchronisation des plateformes.",
                );
                return;
            }
            if (isGamePayload(payload)) {
                onUpdate(payload.data);
            }
        } catch {
            setPlatformsMessage("Impossible de contacter le serveur.");
        } finally {
            setResyncing(false);
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

            <PlatformsField
                game={game}
                onResync={resyncPlatforms}
                onUpdate={onUpdate}
                resyncing={resyncing}
                resyncMessage={platformsMessage}
            />

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

                <div className="grid gap-2 rounded border border-border bg-surface p-4">
                    <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-foreground">
                        <input
                            checked={fields.disabled}
                            className="size-4 rounded border-border accent-accent"
                            type="checkbox"
                            onChange={(e) => setField("disabled", e.target.checked)}
                        />
                        Désactiver le jeu
                    </label>
                    <p className="text-xs text-muted-foreground">
                        Un jeu désactivé reste visible dans les sélecteurs mais ne peut plus être ajouté à une
                        partie. Les slots déjà enregistrés ne sont pas supprimés.
                    </p>
                    {fields.disabled ? (
                        <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                            Message affiché aux joueurs
                            <textarea
                                className={`min-h-20 rounded border bg-background px-3 py-2 text-sm font-normal text-foreground outline-none focus:border-accent ${errors.disabledMessage ? "border-danger" : "border-border"}`}
                                maxLength={500}
                                name="disabledMessage"
                                placeholder="Ex : apworld cassé depuis la v2.1, correction en cours."
                                value={fields.disabledMessage}
                                onChange={(e) => setField("disabledMessage", e.target.value)}
                            />
                            {errors.disabledMessage ?
                                <span className="text-xs text-danger" role="alert">{errors.disabledMessage}</span> : null}
                        </label>
                    ) : null}
                </div>

                <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                    Description
                    <MarkdownEditor
                        maxLength={GAME_DESCRIPTION_MAX}
                        onChange={(v: string) => setField("description", v)}
                        rows={6}
                        value={fields.description}
                    />
                    {errors.description ?
                        <span className="text-xs text-danger" role="alert">{errors.description}</span> : null}
                </label>

                <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                    Description Archipelago
                    <span className="text-xs font-normal text-muted-foreground">
                        Optionnel - ce que le randomizer fait de ce jeu. Affiché sous la description sur la
                        fiche publique.
                    </span>
                    <MarkdownEditor
                        maxLength={GAME_DESCRIPTION_MAX}
                        onChange={(v: string) => setField("archipelagoDescription", v)}
                        rows={6}
                        value={fields.archipelagoDescription}
                    />
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

    const statusColor: Record<ApworldUpdateStatusTone, string> = {
        warning: "text-warning",
        success: "text-success",
        muted: "text-muted-foreground",
        faint: "text-muted-foreground",
    };

    return (
        <Section title="Catalogue & APWorld source">
            {game.updateStatus !== "not_tracked" && (
                <div className="mb-5 flex flex-wrap gap-4 rounded border border-border bg-surface-2 px-4 py-3 text-sm">
          <span className={`font-semibold ${statusColor[updateStatusTone(game.updateStatus)]}`}>
            {updateStatusLabel(game.updateStatus)}
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

/**
 * Story 9.47: IGDB lists every platform the game shipped on, but the Archipelago world often
 * supports only one - so the displayed list is an editorial choice, with the IGDB-derived
 * list one click away.
 */
function PlatformsField({game, resyncing, resyncMessage, onResync, onUpdate}: {
    game: AdminGame;
    resyncing: boolean;
    resyncMessage: string | null;
    onResync: () => void;
    onUpdate: (g: AdminGame) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [selection, setSelection] = useState<string[]>(game.platforms);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const overridden = game.platformsOverridden === true;
    const selectable = game.selectablePlatforms ?? [];

    function toggle(family: string): void {
        setSelection((prev) => prev.includes(family) ? prev.filter((f) => f !== family) : [...prev, family]);
    }

    async function persist(platforms: string[] | null): Promise<void> {
        setSaving(true);
        setError(null);
        const result = await savePlatforms(game.id, platforms);
        if (result.kind === "saved") {
            onUpdate(result.game);
            setSelection(result.game.platforms);
            setEditing(false);
        } else {
            setError(result.message);
        }
        setSaving(false);
    }

    return (
        <div className="mb-5">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                <p className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                    Plateformes
                    <FieldTooltip text="Ce que le catalogue affiche. Par défaut déduit d'IGDB ; à corriger quand le monde Archipelago ne tourne que sur une partie d'entre elles."/>
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    {!editing ? (
                        <button
                            className="inline-flex min-h-9 items-center rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={selectable.length === 0}
                            onClick={() => { setSelection(game.platforms); setEditing(true); }}
                            type="button"
                        >
                            Modifier
                        </button>
                    ) : null}
                    <button
                        className="inline-flex min-h-9 items-center gap-2 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
                        disabled={game.igdbId === null || resyncing || saving}
                        onClick={onResync}
                        type="button"
                    >
                        <RefreshCw aria-hidden="true" className={`size-4 ${resyncing ? "animate-spin" : ""}`}/>
                        Synchroniser depuis IGDB
                    </button>
                </div>
            </div>

            <p className="mb-2 text-xs text-muted-foreground">
                {overridden
                    ? "Liste définie manuellement : une synchronisation IGDB ne la remplacera pas."
                    : "Liste déduite d'IGDB."}
            </p>

            {editing ? (
                <div className="grid gap-3 rounded border border-border bg-surface-2 p-3">
                    <div className="flex flex-wrap gap-1.5">
                        {selectable.map((family) => {
                            const active = selection.includes(family);
                            return (
                                <button
                                    aria-pressed={active}
                                    className={`rounded border px-2 py-1 text-xs transition-colors ${active
                                        ? "border-accent bg-accent/10 text-accent-text"
                                        : "border-border text-muted-foreground hover:text-foreground"}`}
                                    key={family}
                                    onClick={() => toggle(family)}
                                    type="button"
                                >
                                    {family}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            className="inline-flex min-h-9 items-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                            disabled={saving || selection.length === 0}
                            onClick={() => void persist(selection)}
                            type="button"
                        >
                            {saving ? "Enregistrement…" : "Enregistrer"}
                        </button>
                        {overridden ? (
                            <button
                                className="inline-flex min-h-9 items-center rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-60"
                                disabled={saving}
                                onClick={() => void persist(null)}
                                type="button"
                            >
                                Revenir aux plateformes IGDB
                            </button>
                        ) : null}
                        <button
                            className="text-xs text-muted-foreground hover:text-foreground"
                            onClick={() => { setEditing(false); setError(null); }}
                            type="button"
                        >
                            Annuler
                        </button>
                    </div>
                    {selection.length === 0 ? (
                        <p className="text-xs text-warning">Sélectionne au moins une plateforme.</p>
                    ) : null}
                </div>
            ) : game.platforms.length > 0 ? (
                <div className="flex flex-wrap gap-1.5">
                    {game.platforms.map((platform) => (
                        <span
                            className="rounded border border-border bg-surface px-2 py-0.5 text-xs text-muted-foreground"
                            key={platform}
                        >
                            {platform}
                        </span>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    {game.igdbId === null
                        ? "Associe un jeu IGDB ci-dessus puis enregistre pour récupérer les plateformes, ou définis-les à la main."
                        : "Aucune plateforme enregistrée - synchronise depuis IGDB ou définis-les à la main."}
                </p>
            )}

            {error !== null ? <p className="mt-2 text-xs text-danger">{error}</p> : null}
            {resyncMessage ? <p className="mt-2 text-xs text-danger">{resyncMessage}</p> : null}
        </div>
    );
}

// ─── Shared UI primitives ─────────────────────────────────────────────────────

// ─── Section: Tutoriel d'installation (story 31.1) ─────────────────────────────

function InstallTutorialSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {
    const [steps, setSteps] = useState<InstallStep[]>(game.installSteps);
    const [submitting, setSubmitting] = useState(false);
    const [seeding, setSeeding] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function save() {
        setError(null);
        setSuccess(false);
        setSubmitting(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/tutorial`, {
                body: JSON.stringify({steps: serializeStepsForSave(steps)}),
                headers: {"Content-Type": "application/json"},
                method: "PATCH",
            });
            const payload: unknown = await res.json();
            if (!res.ok) {
                setError(extractDetails(payload)["steps"]?.[0] ?? "Le tutoriel contient des erreurs.");
                return;
            }
            if (isGamePayload(payload)) {
                onUpdate(payload.data);
                setSteps(payload.data.installSteps);
                setSuccess(true);
            }
        } catch {
            setError("Impossible de contacter le serveur.");
        } finally {
            setSubmitting(false);
        }
    }

    async function seed() {
        setError(null);
        setSuccess(false);
        setSeeding(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/tutorial/seed`, {method: "POST"});
            const payload: unknown = await res.json();
            if (!res.ok) {
                setError("La génération du brouillon a échoué.");
                return;
            }
            if (isGamePayload(payload)) {
                onUpdate(payload.data);
                setSteps(payload.data.installSteps);
            }
        } catch {
            setError("Impossible de contacter le serveur.");
        } finally {
            setSeeding(false);
        }
    }

    return (
        <Section
            description="Étapes d'installation affichées aux joueurs sur la fiche publique du jeu."
            title="Tutoriel d'installation"
        >
            <div className="grid gap-4">
                <InstallStepsEditor onChange={setSteps} steps={steps}/>

                <div className="flex flex-wrap items-center gap-3">
                    <button
                        className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                        disabled={submitting}
                        onClick={save}
                        type="button"
                    >
                        {submitting ? "Enregistrement…" : "Enregistrer le tutoriel"}
                    </button>
                    <button
                        className="inline-flex min-h-10 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
                        disabled={seeding}
                        onClick={seed}
                        type="button"
                    >
                        {seeding ? "Génération…" : "Générer un brouillon"}
                    </button>
                    {success ? <span className="text-sm text-success">Tutoriel enregistré.</span> : null}
                    {error ? <span className="text-sm text-danger">{error}</span> : null}
                </div>
            </div>
        </Section>
    );
}

function NotesSection({game, onUpdate}: { game: AdminGame; onUpdate: (g: AdminGame) => void }) {
    const [notes, setNotes] = useState(game.adminNotes ?? "");
    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function save() {
        setError(null);
        setSuccess(false);
        setSubmitting(true);
        try {
            const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}/notes`, {
                body: JSON.stringify({adminNotes: notes}),
                headers: {"Content-Type": "application/json"},
                method: "PATCH",
            });
            const payload: unknown = await res.json();
            if (!res.ok) {
                setError(extractDetails(payload)["adminNotes"]?.[0] ?? "L'enregistrement des notes a échoué.");
                return;
            }
            if (isGamePayload(payload)) {
                onUpdate(payload.data);
                setNotes(payload.data.adminNotes ?? "");
                setSuccess(true);
            }
        } catch {
            setError("Impossible de contacter le serveur.");
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Section
            description="Notes internes à l'administration (particularités de l'apworld, pièges de config, historique des décisions). Jamais affichées publiquement."
            title="Notes admin"
        >
            <div className="grid gap-4">
                <textarea
                    aria-label="Notes admin"
                    className="min-h-48 w-full rounded border border-border bg-background px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Rien à signaler pour l'instant…"
                    value={notes}
                />
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                        disabled={submitting}
                        onClick={save}
                        type="button"
                    >
                        {submitting ? "Enregistrement…" : "Enregistrer les notes"}
                    </button>
                    {success ? <span className="text-sm text-success">Notes enregistrées.</span> : null}
                    {error ? <span className="text-sm text-danger">{error}</span> : null}
                </div>
            </div>
        </Section>
    );
}

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


function extractDetails(payload: unknown): Record<string, string[]> {
    if (typeof payload !== "object" || payload === null || !("error" in payload)) return {};
    const err = (payload as { error: unknown }).error;
    if (typeof err !== "object" || err === null || !("details" in err)) return {};
    const d = (err as { details: unknown }).details;
    if (typeof d !== "object" || d === null) return {};
    return d as Record<string, string[]>;
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
