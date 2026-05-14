"use client";

import type { FormEvent } from "react";
import { useEffect, useId, useState } from "react";
import Link from "next/link";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type PostType = "news" | "recap" | "announcement";

type AdminPost = {
  id: string;
  slug: string;
  title: string;
  type: PostType;
  status: "draft" | "published";
  excerpt: string;
  body: string[];
  readingTime: string;
  relatedEventSlug: string | null;
  vodUrl: string | null;
  coverImageUrl: string | null;
  coverImageKey: string | null;
};

type FieldErrors = Partial<Record<
  "slug" | "title" | "type" | "excerpt" | "body" | "readingTime",
  string
>>;

type FormValues = {
  slug: string;
  title: string;
  type: PostType;
  excerpt: string;
  body: string;
  readingTime: string;
  relatedEventSlug: string;
  vodUrl: string;
  coverImageUrl: string;
};

const POST_TYPES: { value: PostType; label: string }[] = [
  { value: "news", label: "Actualité" },
  { value: "recap", label: "Récap" },
  { value: "announcement", label: "Annonce" },
];

const EMPTY_FORM: FormValues = {
  slug: "",
  title: "",
  type: "news",
  excerpt: "",
  body: "",
  readingTime: "",
  relatedEventSlug: "",
  vodUrl: "",
  coverImageUrl: "",
};

export function AdminPostForm({ mode, postId }: { mode: "create" | "edit"; postId?: string }) {
  const [values, setValues] = useState<FormValues>(EMPTY_FORM);
  const [loading, setLoading] = useState(mode === "edit");
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [genericError, setGenericError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [coverMode, setCoverMode] = useState<"url" | "upload">("url");
  const [coverUploading, setCoverUploading] = useState(false);
  const [coverUploadError, setCoverUploadError] = useState<string | null>(null);
  const [uploadedCoverUrl, setUploadedCoverUrl] = useState<string | null>(null);

  useEffect(() => {
    if (mode !== "edit" || !postId) return;

    const controller = new AbortController();

    async function fetchPost() {
      try {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/posts/${postId}`, {
          signal: controller.signal,
        });

        if (!response.ok) {
          setGenericError("Article introuvable ou accès refusé.");
          return;
        }

        const payload: unknown = await response.json();
        const post = isPostPayload(payload) ? payload.data : null;

        if (post) {
          setValues({
            slug: post.slug,
            title: post.title,
            type: post.type,
            excerpt: post.excerpt,
            body: post.body.join("\n"),
            readingTime: post.readingTime,
            relatedEventSlug: post.relatedEventSlug ?? "",
            vodUrl: post.vodUrl ?? "",
            coverImageUrl: post.coverImageKey ? "" : (post.coverImageUrl ?? ""),
          });
          if (post.coverImageKey) {
            setCoverMode("upload");
            setUploadedCoverUrl(post.coverImageUrl ?? null);
          }
        }
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return;
        setGenericError("Impossible de charger l'article.");
      } finally {
        setLoading(false);
      }
    }

    void fetchPost();

    return () => controller.abort();
  }, [mode, postId]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setFieldErrors({});
    setGenericError(null);
    setSuccessMessage(null);

    const body = {
      ...(mode === "create" ? { slug: values.slug.trim() } : {}),
      title: values.title.trim(),
      type: values.type,
      excerpt: values.excerpt.trim(),
      body: values.body
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean),
      readingTime: values.readingTime.trim(),
      relatedEventSlug: values.relatedEventSlug.trim() || null,
      vodUrl: values.vodUrl.trim() || null,
      coverImageMode: coverMode,
      coverImageUrl: coverMode === "url" ? values.coverImageUrl.trim() || null : null,
    };

    const url =
      mode === "create"
        ? `${env.apiBaseUrl}/admin/posts`
        : `${env.apiBaseUrl}/admin/posts/${postId}`;

    const method = mode === "create" ? "POST" : "PATCH";

    try {
      const response = await apiFetch(url, {
        body: JSON.stringify(body),
        headers: { "Content-Type": "application/json" },
        method,
      });

      const payload: unknown = await response.json();

      if (response.status === 422) {
        setFieldErrors(extractFieldErrors(payload));
        return;
      }

      if (!response.ok) {
        setGenericError("L'enregistrement a échoué. Veuillez réessayer.");
        return;
      }

      if (mode === "create") {
        window.location.href = "/admin/actualites";
      } else {
        setSuccessMessage("Article mis à jour.");
      }
    } catch {
      setGenericError("Impossible de contacter l'API.");
    } finally {
      setSubmitting(false);
    }
  }

  const title = mode === "create" ? "Nouveau post" : "Éditer le post";

  return (
    <section className="mx-auto grid w-full max-w-5xl gap-8 px-4 py-10">
      <header className="grid gap-3">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice
        </p>
        <div className="flex items-center justify-between gap-4">
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">{title}</h1>
          <Link
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href="/admin/actualites"
          >
            Retour
          </Link>
        </div>
      </header>

      {loading ? (
        <div aria-busy="true" className="grid gap-4 border border-border bg-surface p-6">
          <div className="h-5 w-48 bg-surface-2" />
          <div className="h-11 bg-surface-2" />
          <div className="h-11 bg-surface-2" />
        </div>
      ) : (
        <form className="grid gap-6 border border-border bg-surface p-6" onSubmit={submit}>
          {genericError ? (
            <p className="border border-danger/50 p-3 text-sm text-danger" role="alert">
              {genericError}
            </p>
          ) : null}

          {successMessage ? (
            <p className="border border-success/50 p-3 text-sm text-success" role="status">
              {successMessage}
            </p>
          ) : null}

          <div className="grid gap-4 md:grid-cols-2">
            {mode === "create" ? (
              <TextField
                error={fieldErrors.slug}
                label="Slug (URL)"
                onChange={(v) => setValues((prev) => ({ ...prev, slug: v }))}
                placeholder="mon-article-de-test"
                type="text"
                value={values.slug}
              />
            ) : (
              <div className="grid gap-2 text-sm font-medium text-foreground">
                Slug
                <p className="min-h-11 border border-border bg-background px-3 py-3 font-mono text-muted-foreground">
                  {values.slug}
                </p>
              </div>
            )}

            <TypeSelect
              error={fieldErrors.type}
              onChange={(v) => setValues((prev) => ({ ...prev, type: v }))}
              value={values.type}
            />
          </div>

          <TextField
            error={fieldErrors.title}
            label="Titre"
            onChange={(v) => setValues((prev) => ({ ...prev, title: v }))}
            placeholder="Titre de l'article"
            type="text"
            value={values.title}
          />

          <TextField
            error={fieldErrors.excerpt}
            label="Extrait"
            onChange={(v) => setValues((prev) => ({ ...prev, excerpt: v }))}
            placeholder="Court résumé affiché dans les listes."
            type="text"
            value={values.excerpt}
          />

          <TextareaField
            error={fieldErrors.body}
            label="Corps (un paragraphe par ligne)"
            onChange={(v) => setValues((prev) => ({ ...prev, body: v }))}
            placeholder={"Premier paragraphe.\nDeuxième paragraphe."}
            rows={8}
            value={values.body}
          />

          <div className="grid gap-4 md:grid-cols-3">
            <TextField
              error={fieldErrors.readingTime}
              label="Temps de lecture"
              onChange={(v) => setValues((prev) => ({ ...prev, readingTime: v }))}
              placeholder="3 min"
              type="text"
              value={values.readingTime}
            />
            <TextField
              label="Slug de l'événement lié (optionnel)"
              onChange={(v) => setValues((prev) => ({ ...prev, relatedEventSlug: v }))}
              placeholder="lan-printemps-2026"
              type="text"
              value={values.relatedEventSlug}
            />
            <TextField
              label="URL VOD (optionnel)"
              onChange={(v) => setValues((prev) => ({ ...prev, vodUrl: v }))}
              placeholder="https://youtube.com/..."
              type="url"
              value={values.vodUrl}
            />
          </div>

          <div className="grid gap-2">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-foreground">Image de couverture (optionnel)</span>
              <div className="flex rounded border border-border text-xs">
                <button
                  className={["px-2 py-1 transition-colors", coverMode === "url" ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"].join(" ")}
                  onClick={() => setCoverMode("url")}
                  type="button"
                >
                  URL
                </button>
                <button
                  className={["px-2 py-1 transition-colors", coverMode === "upload" ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"].join(" ")}
                  onClick={() => setCoverMode("upload")}
                  type="button"
                >
                  Upload
                </button>
              </div>
            </div>
            {coverMode === "url" ? (
              <input
                className="min-h-11 border border-border bg-background px-3 outline-none focus:border-accent"
                onChange={(e) => setValues((prev) => ({ ...prev, coverImageUrl: e.target.value }))}
                placeholder="https://cdn.archilan.fr/..."
                type="url"
                value={values.coverImageUrl}
              />
            ) : (
              <div className="grid gap-2">
                <input
                  accept="image/jpeg,image/png,image/webp"
                  className="text-sm text-foreground file:mr-2 file:rounded file:border-0 file:bg-accent file:px-2 file:py-1 file:text-xs file:text-white"
                  disabled={coverUploading || !postId}
                  onChange={async (e) => {
                    const file = e.target.files?.[0];
                    if (!file || !postId) return;
                    setCoverUploadError(null);
                    setCoverUploading(true);
                    try {
                      const fd = new FormData();
                      fd.append("file", file);
                      const res = await apiFetch(`${env.apiBaseUrl}/admin/posts/${postId}/cover-image`, { method: "POST", body: fd });
                      if (!res.ok) {
                        const body = await res.json() as { error?: { code?: string } };
                        const code = body?.error?.code;
                        setCoverUploadError(code === "image_invalid_type" ? "Type non supporté (JPEG, PNG ou WebP uniquement)." : code === "image_too_large" ? "Fichier trop volumineux (max 10 Mo)." : "Erreur lors de l'upload.");
                      } else {
                        const body = await res.json() as { data?: AdminPost };
                        setUploadedCoverUrl(body?.data?.coverImageUrl ?? null);
                        if (body.data) {
                          setValues((prev) => ({ ...prev, coverImageUrl: "" }));
                          setCoverMode("upload");
                        }
                      }
                    } catch {
                      setCoverUploadError("Erreur réseau lors de l'upload.");
                    } finally {
                      setCoverUploading(false);
                    }
                  }}
                  type="file"
                />
                {coverUploading && <span className="text-xs text-muted-foreground">Upload en cours…</span>}
                {coverUploadError && <span className="text-xs text-danger">{coverUploadError}</span>}
                {uploadedCoverUrl && !coverUploadError && (
                  <div
                    aria-label="Aperçu cover"
                    className="h-20 w-20 rounded border border-border bg-contain bg-center bg-no-repeat"
                    role="img"
                    style={{ backgroundImage: `url("${uploadedCoverUrl}")` }}
                  />
                )}
                {!postId && <span className="text-xs text-muted-foreground">Enregistrez d&apos;abord l&apos;article avant d&apos;uploader une image.</span>}
              </div>
            )}
          </div>

          <div>
            <button
              className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-6 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
              disabled={submitting}
              type="submit"
            >
              {submitting
                ? "Enregistrement..."
                : mode === "create"
                  ? "Créer le brouillon"
                  : "Enregistrer les modifications"}
            </button>
          </div>
        </form>
      )}
    </section>
  );
}

function TextField({
  error,
  label,
  onChange,
  placeholder,
  type,
  value,
}: {
  error?: string;
  label: string;
  onChange: (value: string) => void;
  placeholder?: string;
  type: string;
  value: string;
}) {
  const id = useId();
  const errorId = `${id}-error`;

  return (
    <label className="grid gap-2 text-sm font-medium text-foreground">
      {label}
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        className="min-h-11 border border-border bg-background px-3 outline-none focus:border-accent"
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        type={type}
        value={value}
      />
      {error ? (
        <span className="text-xs text-danger" id={errorId}>
          {error}
        </span>
      ) : null}
    </label>
  );
}

function TextareaField({
  error,
  label,
  onChange,
  placeholder,
  rows,
  value,
}: {
  error?: string;
  label: string;
  onChange: (value: string) => void;
  placeholder?: string;
  rows: number;
  value: string;
}) {
  const id = useId();
  const errorId = `${id}-error`;

  return (
    <label className="grid gap-2 text-sm font-medium text-foreground">
      {label}
      <textarea
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        className="border border-border bg-background px-3 py-2 outline-none focus:border-accent"
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        rows={rows}
        value={value}
      />
      {error ? (
        <span className="text-xs text-danger" id={errorId}>
          {error}
        </span>
      ) : null}
    </label>
  );
}

function TypeSelect({
  error,
  onChange,
  value,
}: {
  error?: string;
  onChange: (value: PostType) => void;
  value: PostType;
}) {
  const id = useId();
  const errorId = `${id}-error`;

  return (
    <label className="grid gap-2 text-sm font-medium text-foreground">
      Type
      <select
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        className="min-h-11 border border-border bg-background px-3 text-foreground outline-none focus:border-accent"
        onChange={(e) => onChange(e.target.value as PostType)}
        value={value}
      >
        {POST_TYPES.map(({ label, value: v }) => (
          <option key={v} value={v}>
            {label}
          </option>
        ))}
      </select>
      {error ? (
        <span className="text-xs text-danger" id={errorId}>
          {error}
        </span>
      ) : null}
    </label>
  );
}

function isPostPayload(payload: unknown): payload is { data: AdminPost } {
  if (!payload || typeof payload !== "object" || !("data" in payload)) return false;
  const data = (payload as { data: unknown }).data;
  return Boolean(data && typeof data === "object" && "id" in data && "slug" in data);
}

function extractFieldErrors(payload: unknown): FieldErrors {
  if (!payload || typeof payload !== "object" || !("error" in payload)) return {};
  const error = (payload as { error: unknown }).error;
  if (!error || typeof error !== "object" || !("details" in error)) return {};
  const details = (error as { details: unknown }).details;
  if (!details || typeof details !== "object") return {};

  const d = details as Record<string, unknown>;

  function first(key: string): string | undefined {
    const v = d[key];
    return Array.isArray(v) && typeof v[0] === "string" ? v[0] : undefined;
  }

  return {
    slug: first("slug"),
    title: first("title"),
    type: first("type"),
    excerpt: first("excerpt"),
    readingTime: first("readingTime"),
  };
}
