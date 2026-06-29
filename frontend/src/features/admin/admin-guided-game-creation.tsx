"use client";

import { ArrowLeft, ExternalLink, Info, Loader2, Search } from "lucide-react";
import Link from "next/link";
import type { FormEvent } from "react";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ── Types ─────────────────────────────────────────────────────────────────────

export type CataloguePreset = {
  name: string;
  availability: string;
  bundledWithAp: boolean;
  adultContent: boolean;
  links: { label: string; url: string | null }[];
};

type IgdbCandidate = {
  igdbId: number;
  name: string;
  summary: string | null;
  coverUrl: string | null;
};

type Fields = {
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string;
  coverImageAlt: string;
  coverImageCredit: string;
  availability: string;
  catalogSheetName: string;
  adultContent: boolean;
  bundledWithAp: boolean;
  availabilityLocked: boolean;
  apworldSourceUrl: string;
  igdbId: number | null;
};

type FieldErrors = Partial<Record<string, string>>;

// ── Helpers ───────────────────────────────────────────────────────────────────

function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}

function detectGitHubUrl(links: { url: string | null }[]): string {
  return links.find((l) => l.url?.startsWith("https://github.com/"))?.url ?? "";
}

function isValidApworldSourceUrl(url: string): boolean {
  // GitLab direct file URL
  if (url.startsWith("https://gitlab.com/")) {
    return /\/-\/(blob|raw)\/.+\.apworld$/i.test(url);
  }

  if (!url.startsWith("https://github.com/")) return false;
  const cleanUrl = url.split("?")[0].split("#")[0];

  // Direct .apworld file URL (raw file link)
  if (/\.apworld$/i.test(cleanUrl)) return true;

  const path = cleanUrl.slice("https://github.com/".length).replace(/\/$/, "");
  const parts = path.split("/");
  if (parts.length < 2 || !parts[0] || !parts[1]) return false;
  const rest = parts.slice(2);
  if (rest.length === 0) return true;
  if (rest.length === 1 && rest[0] === "releases") return true;
  if (rest.length === 2 && rest[0] === "releases" && rest[1] === "latest") return true;
  if (rest.length === 3 && rest[0] === "releases" && rest[1] === "tag" && rest[2]) return true;
  if (rest.length >= 2 && rest[0] === "tree" && rest[1]) return true;
  return false;
}

function extractErrors(payload: unknown): FieldErrors {
  const details =
    payload &&
    typeof payload === "object" &&
    "error" in payload &&
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
    apworldSourceUrl: first("apworldSourceUrl"),
    catalogSheetName: first("catalogSheetName"),
  };
}

function extractId(payload: unknown): string | null {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;
  return data &&
    typeof data === "object" &&
    "id" in data &&
    typeof (data as { id: unknown }).id === "string"
    ? (data as { id: string }).id
    : null;
}

// ── Main component ─────────────────────────────────────────────────────────────

export function AdminGuidedGameCreation({ preset }: { preset: CataloguePreset }) {
  const [fields, setFields] = useState<Fields>(() => ({
    name: preset.name,
    slug: slugify(preset.name),
    description: "",
    coverImageUrl: "",
    coverImageAlt: "",
    coverImageCredit: "",
    availability: preset.availability,
    catalogSheetName: preset.name,
    adultContent: preset.adultContent,
    bundledWithAp: preset.bundledWithAp,
    availabilityLocked: false,
    apworldSourceUrl: detectGitHubUrl(preset.links),
    igdbId: null,
  }));
  const [errors, setErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);

  function setField<K extends keyof Fields>(key: K, value: Fields[K]) {
    setFields((f) => ({ ...f, [key]: value }));
  }

  function handleIgdbSelect(candidate: IgdbCandidate) {
    setFields((f) => ({
      ...f,
      description: candidate.summary ?? f.description,
      coverImageUrl: candidate.coverUrl ?? f.coverImageUrl,
      coverImageAlt: `${candidate.name} - cover`,
      coverImageCredit: "",
      igdbId: candidate.igdbId,
    }));
  }

  function validateForm(): boolean {
    const e: FieldErrors = {};
    if (!fields.name.trim()) e.name = "Le nom est obligatoire.";
    if (fields.apworldSourceUrl && !isValidApworldSourceUrl(fields.apworldSourceUrl)) {
      e.apworldSourceUrl =
        "URL invalide. Formats acceptés : https://github.com/{owner}/{repo} (et variantes releases/raw), URL directe .apworld, ou https://gitlab.com/{owner}/{repo}/-/blob/{branch}/{file}.apworld.";
    }
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!validateForm()) return;
    setSubmitting(true);

    const input: Record<string, unknown> = {
      name: fields.name,
      slug: fields.slug,
      description: fields.description,
      coverImageUrl: fields.coverImageUrl || null,
      coverImageAlt: fields.coverImageAlt,
      coverImageCredit: fields.coverImageCredit,
      availability: fields.availability,
      adult_content: fields.adultContent,
      bundled_with_ap: fields.bundledWithAp,
      availability_locked: fields.availabilityLocked,
    };
    if (fields.catalogSheetName) input.catalog_sheet_name = fields.catalogSheetName;
    if (fields.apworldSourceUrl) input.apworld_source_url = fields.apworldSourceUrl;
    if (fields.igdbId !== null) input.igdb_id = fields.igdbId;

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/games`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(input),
      });

      const payload: unknown = await res.json();

      if (!res.ok) {
        setErrors(extractErrors(payload));
        return;
      }

      const id = extractId(payload);
      window.location.href = id ? `/admin/jeux/${id}` : "/admin/jeux";
    } catch {
      setErrors({ name: "Impossible de contacter le serveur." });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-10">
      <header className="mb-8 grid gap-3">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex items-center justify-between gap-4">
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">Nouveau jeu</h1>
          <Link
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href="/admin/catalogue"
          >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Retour au catalogue
          </Link>
        </div>
        <p className="font-mono text-sm text-muted-foreground">Depuis le sheet : {preset.name}</p>
      </header>

      <form className="grid gap-6" onSubmit={handleSubmit}>
        {/* Section 1 - Données du sheet (pré-remplis, modifiables) */}
        <FormSection
          title="Données du sheet"
          description="Pré-remplis depuis le Google Sheet communautaire - modifiables."
        >
          <div className="grid items-start gap-4 md:grid-cols-2">
            <TextField
              error={errors.name}
              label="Nom"
              name="name"
              value={fields.name}
              onChange={(v) => {
                setField("name", v);
                setField("slug", slugify(v));
              }}
            />
            <TextField
              error={errors.slug}
              hint="Auto-généré depuis le nom. Non modifiable après création."
              label="Slug"
              name="slug"
              value={fields.slug}
              onChange={(v) => setField("slug", v)}
            />
            <AvailabilitySelect
              error={errors.availability}
              value={fields.availability}
              onChange={(v) => setField("availability", v)}
            />
          </div>
          <div className="mt-4 grid items-start gap-4 md:grid-cols-2">
            <TextField
              label="URL couverture"
              name="coverImageUrl"
              placeholder="https://images.igdb.com/…"
              value={fields.coverImageUrl}
              onChange={(v) => setField("coverImageUrl", v)}
            />
            <TextField
              hint="Généré automatiquement lors de la sélection d'un candidat IGDB."
              label="Texte alternatif couverture"
              name="coverImageAlt"
              value={fields.coverImageAlt}
              onChange={(v) => setField("coverImageAlt", v)}
            />
            <TextField
              label="Crédit image couverture"
              name="coverImageCredit"
              value={fields.coverImageCredit}
              onChange={(v) => setField("coverImageCredit", v)}
            />
          </div>
          <div className="mt-4">
            <TextAreaField
              error={errors.description}
              label="Description"
              name="description"
              value={fields.description}
              onChange={(v) => setField("description", v)}
            />
          </div>
        </FormSection>

        {/* Section 2 - Liens du sheet (lecture seule) */}
        <SheetLinksSection links={preset.links} />

        {/* Section 3 - Candidats IGDB */}
        <IgdbCandidatesSection
          initialQuery={preset.name}
          selectedIgdbId={fields.igdbId}
          onDeselect={() => setField("igdbId", null)}
          onSelect={handleIgdbSelect}
        />

        {/* Section 4 - Source APWorld */}
        <FormSection
          description="URL du dépôt GitHub source de l'APWorld."
          title="Source APWorld"
        >
          <TextField
            error={errors.apworldSourceUrl}
            hint="https://github.com/{owner}/{repo} - et variantes : /releases, /releases/latest, /releases/tag/{tag}, /tree/{branch}"
            label="URL source APWorld"
            name="apworldSourceUrl"
            placeholder="https://github.com/owner/repo"
            value={fields.apworldSourceUrl}
            onChange={(v) => setField("apworldSourceUrl", v)}
          />
          <p className="mt-2 text-xs text-muted-foreground">
            La version sera vérifiée lors de la prochaine exécution du vérificateur de mises à jour.
          </p>
        </FormSection>

        {/* Section 5 - Champs spécifiques AP (manuels) */}
        <FormSection
          description="Métadonnées du catalogue Archipelago."
          title="Champs spécifiques AP"
        >
          <div className="grid gap-4">
            <TextField
              hint="Doit correspondre exactement au nom dans le Google Sheet pour la synchronisation automatique."
              label="Nom dans le sheet (catalog_sheet_name)"
              name="catalogSheetName"
              value={fields.catalogSheetName}
              onChange={(v) => setField("catalogSheetName", v)}
            />
            <div className="grid gap-3 sm:grid-cols-3">
              <CheckboxField
                checked={fields.adultContent}
                label="Contenu adulte (18+)"
                onChange={(v) => setField("adultContent", v)}
              />
              <CheckboxField
                checked={fields.bundledWithAp}
                label="Intégré à Archipelago"
                onChange={(v) => setField("bundledWithAp", v)}
              />
              <CheckboxField
                checked={fields.availabilityLocked}
                label="Disponibilité verrouillée"
                onChange={(v) => setField("availabilityLocked", v)}
              />
            </div>
          </div>
        </FormSection>

        <div className="flex items-center gap-3">
          <button
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? (
              <>
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                Création…
              </>
            ) : (
              "Créer le jeu"
            )}
          </button>
        </div>
      </form>
    </div>
  );
}

// ── Sheet links section ────────────────────────────────────────────────────────

function SheetLinksSection({ links }: { links: { label: string; url: string | null }[] }) {
  if (links.length === 0) return null;

  const hasNullUrls = links.some((l) => l.url === null);
  const description = hasNullUrls
    ? "Liens provenant du Google Sheet (lecture seule). Les URLs sont indisponibles sans clé Google API."
    : "Liens provenant du Google Sheet communautaire (lecture seule).";

  return (
    <FormSection
      description={description}
      title="Liens du sheet"
    >
      <ul className="flex flex-wrap gap-3">
        {links.map((link, i) =>
          link.url ? (
            <li key={i}>
              <a
                className="inline-flex items-center gap-1.5 rounded border border-border px-3 py-1.5 text-sm text-accent-text hover:underline"
                href={link.url}
                rel="noopener"
                target="_blank"
              >
                {link.label}
                <ExternalLink aria-hidden="true" className="size-3.5" />
              </a>
            </li>
          ) : (
            <li key={i}>
              <span className="inline-flex items-center gap-1.5 rounded border border-border px-3 py-1.5 text-sm text-muted-foreground/50">
                {link.label}
              </span>
            </li>
          ),
        )}
      </ul>
    </FormSection>
  );
}

// ── IGDB candidates section ────────────────────────────────────────────────────

const IGDB_PAGE_SIZE = 6;

type CandidateStatus = "loading" | "done" | "error";

function IgdbCandidatesSection({
  initialQuery,
  selectedIgdbId,
  onDeselect,
  onSelect,
}: {
  initialQuery: string;
  selectedIgdbId: number | null;
  onDeselect: () => void;
  onSelect: (c: IgdbCandidate) => void;
}) {
  const [inputValue, setInputValue] = useState(initialQuery);
  const [activeQuery, setActiveQuery] = useState(initialQuery);
  const [page, setPage] = useState(0);
  const [candidates, setCandidates] = useState<IgdbCandidate[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [status, setStatus] = useState<CandidateStatus>("loading");

  useEffect(() => {
    let cancelled = false;
    async function fetch() {
      setStatus("loading");
      try {
        const offset = page * IGDB_PAGE_SIZE;
        const res = await apiFetch(
          `${env.apiBaseUrl}/admin/igdb/search?q=${encodeURIComponent(activeQuery)}&offset=${offset}`,
        );
        if (cancelled) return;
        if (!res.ok) { setStatus("error"); return; }
        const body: unknown = await res.json();
        if (!isIgdbSearchPayload(body)) { setStatus("error"); return; }
        setCandidates(body.data);
        setHasMore(body.meta.hasMore);
        setStatus("done");
      } catch {
        if (!cancelled) setStatus("error");
      }
    }
    void fetch();
    return () => { cancelled = true; };
  }, [activeQuery, page]);

  function triggerSearch() {
    const trimmed = inputValue.trim();
    if (!trimmed) return;
    setPage(0);
    setActiveQuery(trimmed);
  }

  return (
    <FormSection title="Candidats IGDB">
      {/* Search bar - intentionally NOT a <form> to avoid nesting inside the page form */}
      <div className="mb-5 flex gap-2">
        <div className="relative flex-1">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          />
          <input
            className="min-h-10 w-full rounded border border-border bg-background py-0 pl-9 pr-3 text-sm outline-none focus:border-accent"
            placeholder="Rechercher sur IGDB…"
            type="search"
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); triggerSearch(); } }}
          />
        </div>
        <button
          className="inline-flex min-h-10 items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
          disabled={status === "loading"}
          type="button"
          onClick={triggerSearch}
        >
          {status === "loading" ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : null}
          Rechercher
        </button>
      </div>

      {/* Results */}
      {status === "loading" ? (
        <p className="text-sm text-muted-foreground">Recherche en cours…</p>
      ) : status === "error" ? (
        <p className="text-sm text-danger">Erreur lors de la recherche IGDB.</p>
      ) : candidates.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun résultat pour « {activeQuery} ».</p>
      ) : (
        <>
          <ul className="grid gap-3 sm:grid-cols-3">
            {candidates.map((c) => {
              const selected = c.igdbId === selectedIgdbId;
              return (
                <li key={c.igdbId}>
                  <button
                    className={`flex w-full items-start gap-3 rounded border p-3 text-left transition-colors hover:border-accent ${selected ? "border-accent bg-accent/5" : "border-border"}`}
                    type="button"
                    onClick={() => onSelect(c)}
                  >
                    {c.coverUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img alt="" className="h-16 w-12 shrink-0 rounded object-cover" src={c.coverUrl} />
                    ) : (
                      <div className="flex h-16 w-12 shrink-0 items-center justify-center rounded bg-surface-2 text-xs text-muted-foreground">
                        N/A
                      </div>
                    )}
                    <div className="min-w-0">
                      <span className="text-sm font-medium text-foreground">{c.name}</span>
                      {selected ? (
                        <span className="mt-1 block text-xs font-normal text-accent">Sélectionné</span>
                      ) : null}
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
          {(page > 0 || hasMore) && (
            <div className="mt-4 flex items-center justify-between">
              <button
                className="text-sm text-muted-foreground hover:text-foreground disabled:opacity-30"
                disabled={page === 0}
                type="button"
                onClick={() => setPage((p) => p - 1)}
              >
                ← Précédent
              </button>
              <span className="text-xs text-muted-foreground">Page {page + 1}</span>
              <button
                className="text-sm text-muted-foreground hover:text-foreground disabled:opacity-30"
                disabled={!hasMore}
                type="button"
                onClick={() => setPage((p) => p + 1)}
              >
                Suivant →
              </button>
            </div>
          )}
          {selectedIgdbId !== null ? (
            <p className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
              <Search aria-hidden="true" className="size-3.5" />
              ID IGDB lié : <span className="font-mono">{selectedIgdbId}</span>
              <button className="ml-1 text-danger hover:underline" type="button" onClick={onDeselect}>
                Dissocier
              </button>
            </p>
          ) : null}
        </>
      )}
    </FormSection>
  );
}

function isIgdbSearchPayload(v: unknown): v is { data: IgdbCandidate[]; meta: { hasMore: boolean } } {
  if (typeof v !== "object" || v === null || !("data" in v) || !("meta" in v)) return false;
  if (!Array.isArray((v as { data: unknown }).data)) return false;
  const meta = (v as { meta: unknown }).meta;
  return typeof meta === "object" && meta !== null && "hasMore" in meta && typeof (meta as { hasMore: unknown }).hasMore === "boolean";
}

// ── Shared UI primitives ───────────────────────────────────────────────────────

function FormSection({
  children,
  description,
  title,
}: {
  children: React.ReactNode;
  description?: string;
  title: string;
}) {
  return (
    <section className="rounded-lg border border-border bg-surface p-6">
      <h2 className="font-heading text-lg font-semibold text-foreground">{title}</h2>
      {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
      <div className="mt-5">{children}</div>
    </section>
  );
}

function TextField({
  error,
  hint,
  label,
  name,
  placeholder,
  value,
  onChange,
}: {
  error?: string;
  hint?: string;
  label: string;
  name: string;
  placeholder?: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="grid gap-1.5 text-sm font-semibold text-foreground">
      <span className="flex items-center gap-1.5">
        {label}
        {hint ? <FieldTooltip text={hint} /> : null}
      </span>
      <input
        className={`min-h-11 rounded border bg-background px-3 outline-none focus:border-accent ${error ? "border-danger" : "border-border"}`}
        name={name}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      {error ? (
        <span className="text-xs text-danger" role="alert">
          {error}
        </span>
      ) : null}
    </label>
  );
}

function TextAreaField({
  error,
  label,
  name,
  value,
  onChange,
}: {
  error?: string;
  label: string;
  name: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="grid gap-1.5 text-sm font-semibold text-foreground">
      {label}
      <textarea
        className={`min-h-28 rounded border bg-background px-3 py-2 outline-none focus:border-accent ${error ? "border-danger" : "border-border"}`}
        name={name}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      {error ? (
        <span className="text-xs text-danger" role="alert">
          {error}
        </span>
      ) : null}
    </label>
  );
}

function AvailabilitySelect({
  error,
  value,
  onChange,
}: {
  error?: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="grid gap-1.5 text-sm font-semibold text-foreground">
      Disponibilité
      <select
        className={`min-h-11 rounded border bg-background px-3 text-foreground outline-none focus:border-accent ${error ? "border-danger" : "border-border"}`}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      >
        <option value="available">Disponible</option>
        <option value="experimental">Expérimental</option>
        <option value="unavailable">Indisponible</option>
      </select>
      {error ? (
        <span className="text-xs text-danger" role="alert">
          {error}
        </span>
      ) : null}
    </label>
  );
}

function CheckboxField({
  checked,
  label,
  onChange,
}: {
  checked: boolean;
  label: string;
  onChange: (value: boolean) => void;
}) {
  return (
    <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-foreground">
      <input
        checked={checked}
        className="size-4 rounded border-border accent-accent"
        type="checkbox"
        onChange={(e) => onChange(e.target.checked)}
      />
      {label}
    </label>
  );
}

function FieldTooltip({ text }: { text: string }) {
  return (
    <span className="group relative inline-flex">
      <Info aria-hidden="true" className="size-3.5 cursor-help text-muted-foreground" />
      <span className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-56 -translate-x-1/2 rounded border border-border bg-surface-2 px-2.5 py-2 text-xs font-normal leading-relaxed text-muted-foreground opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
        {text}
      </span>
    </span>
  );
}
