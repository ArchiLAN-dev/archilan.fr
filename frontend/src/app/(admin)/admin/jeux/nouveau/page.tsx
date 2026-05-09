"use client";

import { ArrowLeft, Info } from "lucide-react";
import Link from "next/link";
import type { FormEvent } from "react";
import { useState } from "react";

import { IgdbGameSearch, type IgdbResult } from "@/features/admin/igdb-game-search";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type GameAvailability = "available" | "unavailable" | "experimental";

type FieldErrors = Partial<
  Record<
    | "name"
    | "slug"
    | "description"
    | "coverImageUrl"
    | "coverImageAlt"
    | "coverImageCredit"
    | "availability",
    string
  >
>;

type Fields = {
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string;
  coverImageAlt: string;
  coverImageCredit: string;
  availability: GameAvailability;
};

function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}

export default function AdminNewGamePage() {
  const [errors, setErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);
  const [fields, setFields] = useState<Fields>({
    name: "",
    slug: "",
    description: "",
    coverImageUrl: "",
    coverImageAlt: "",
    coverImageCredit: "",
    availability: "available",
  });

  function setField<K extends keyof Fields>(key: K, value: Fields[K]) {
    setFields((f) => ({ ...f, [key]: value }));
  }

  function handleIgdbSelect(result: IgdbResult) {
    setFields((f) => ({
      ...f,
      name: result.name,
      slug: slugify(result.name),
      description: result.summary ? result.summary.slice(0, 500) : "",
      coverImageUrl: result.coverUrl ?? "",
      coverImageCredit: "© IGDB",
    }));
  }

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setErrors({});
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
      const res = await apiFetch(`${env.apiBaseUrl}/admin/games`, {
        body: JSON.stringify(input),
        headers: { "Content-Type": "application/json" },
        method: "POST",
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
            href="/admin/jeux"
          >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Retour
          </Link>
        </div>
      </header>

      <section className="mb-6 rounded-lg border border-border bg-surface p-6">
        <h2 className="font-heading flex items-center gap-1.5 text-lg font-semibold text-foreground">
          Importer depuis IGDB
          <Tooltip text="Optionnel - les champs restent modifiables après import." />
        </h2>
        <div className="mt-4">
          <IgdbGameSearch onSelect={handleIgdbSelect} />
        </div>
      </section>

      <section className="rounded-lg border border-border bg-surface p-6">
        <form className="grid gap-4" onSubmit={submit}>
          <div className="grid items-start gap-4 md:grid-cols-2">
            <ControlledField
              error={errors.name}
              label="Nom"
              name="name"
              placeholder="Hollow Knight"
              value={fields.name}
              onChange={(v) => setField("name", v)}
            />
            <ControlledField
              error={errors.slug}
              hint="Lettres minuscules, chiffres et tirets (ex : hollow-knight). Non modifiable après création."
              label="Slug"
              name="slug"
              placeholder="hollow-knight"
              value={fields.slug}
              onChange={(v) => setField("slug", v)}
            />
            <ControlledField
              error={errors.coverImageUrl}
              hint="URL publique de l'image de couverture (HTTPS). Préremplie depuis IGDB si disponible."
              label="URL de la couverture"
              name="coverImageUrl"
              placeholder="https://images.igdb.com/…"
              value={fields.coverImageUrl}
              onChange={(v) => setField("coverImageUrl", v)}
            />
            <ControlledField
              error={errors.coverImageAlt}
              hint="Optionnel. Description de l'image pour les lecteurs d'écran et le référencement."
              label="Texte alternatif de la couverture"
              name="coverImageAlt"
              value={fields.coverImageAlt}
              onChange={(v) => setField("coverImageAlt", v)}
            />
            <ControlledField
              error={errors.coverImageCredit}
              hint="Auteur ou source de l'image (ex : © Team Cherry, © IGDB)."
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
              {errors.availability ? (
                <span className="text-xs text-danger" role="alert">
                  {errors.availability}
                </span>
              ) : null}
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
            {errors.description ? (
              <span className="text-xs text-danger" role="alert">
                {errors.description}
              </span>
            ) : null}
          </label>

          <button
            className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? "Création…" : "Créer le jeu"}
          </button>

          <p className="text-xs text-muted-foreground">
            Le fichier .apworld est configurable après la création.
          </p>
        </form>
      </section>
    </div>
  );
}

function ControlledField({
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
        {hint ? <Tooltip text={hint} /> : null}
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

function Tooltip({ text }: { text: string }) {
  return (
    <span className="group relative inline-flex">
      <Info aria-hidden="true" className="size-3.5 cursor-help text-muted-foreground" />
      <span className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-56 -translate-x-1/2 rounded border border-border bg-surface-2 px-2.5 py-2 text-xs font-normal leading-relaxed text-muted-foreground opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
        {text}
      </span>
    </span>
  );
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
