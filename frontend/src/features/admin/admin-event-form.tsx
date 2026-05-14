"use client";

import { useId, useState } from "react";
import type { ChangeEvent, FormEvent } from "react";

import { DateTimePicker } from "@/components/date-time-picker";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type EventFormInput = {
  title: string;
  description: string;
  coverImageUrl: string | null;
  coverImageMode: "url" | "upload";
  photoGallery: string[];
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
};

export type EventField =
  | "title"
  | "description"
  | "coverImageUrl"
  | "photoGallery"
  | "startsAt"
  | "endsAt"
  | "venue"
  | "capacity"
  | "registrationOpensAt"
  | "registrationClosesAt";

export type FieldErrors = Partial<Record<EventField, string>>;

export type AdminEventFormData = {
  id: string;
  title: string;
  description: string;
  coverImageUrl: string | null;
  coverImageKey: string | null;
  photoGallery: string[];
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
};

export class EventDraftError extends Error {
  constructor(readonly fieldErrors: FieldErrors) {
    super("event-draft-failed");
  }
}

export function EventForm({
  event,
  mode,
  onSubmit,
  onEventUpdated,
}: {
  event: AdminEventFormData | null;
  mode: "create" | "edit";
  onSubmit: (input: EventFormInput) => Promise<void>;
  onEventUpdated?: (event: AdminEventFormData) => void;
}) {
  const [errors, setErrors] = useState<FieldErrors>({});
  const [genericError, setGenericError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [isPublic, setIsPublic] = useState(event?.isPublic ?? true);
  const [coverMode, setCoverMode] = useState<"url" | "upload">(
    event?.coverImageKey ? "upload" : "url"
  );
  const [coverUploadError, setCoverUploadError] = useState<string | null>(null);
  const [coverUploading, setCoverUploading] = useState(false);
  const [uploadedCoverUrl, setUploadedCoverUrl] = useState<string | null>(
    event?.coverImageKey ? (event.coverImageUrl ?? null) : null
  );
  const descriptionErrorId = useId();
  const photoGalleryErrorId = useId();

  async function submit(formEvent: FormEvent<HTMLFormElement>) {
    formEvent.preventDefault();
    setErrors({});
    setGenericError(null);
    setSubmitting(true);

    const form = new FormData(formEvent.currentTarget);
    const formElement = formEvent.currentTarget;

    try {
      await onSubmit({
        title: String(form.get("title") ?? ""),
        description: String(form.get("description") ?? ""),
        coverImageUrl: coverMode === "url" ? optionalUrl(String(form.get("coverImageUrl") ?? "")) : null,
        coverImageMode: coverMode,
        photoGallery: textareaUrls(String(form.get("photoGallery") ?? "")),
        startsAt: localDateTime(String(form.get("startsAt") ?? "")),
        endsAt: localDateTime(String(form.get("endsAt") ?? "")),
        venue: String(form.get("venue") ?? ""),
        capacity: Number(form.get("capacity") ?? 0),
        registrationOpensAt: localDateTime(String(form.get("registrationOpensAt") ?? "")),
        registrationClosesAt: localDateTime(String(form.get("registrationClosesAt") ?? "")),
        isPublic: form.get("isPublic") === "on",
      });

      if (mode === "create") {
        formElement.reset();
      }
    } catch (error) {
      if (error instanceof EventDraftError) {
        setErrors(error.fieldErrors);
      } else {
        setGenericError("Impossible d'enregistrer l'événement pour le moment.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="grid gap-4" onSubmit={submit}>
      {genericError ? (
        <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
          {genericError}
        </p>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2">
        <EventTextField defaultValue={event?.title} error={errors.title} label="Titre" name="title" />
        <div className="grid gap-2">
          <div className="flex items-center gap-2">
            <span className="text-sm font-semibold text-foreground">Image de couverture</span>
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
            <EventTextField defaultValue={event?.coverImageUrl ?? ""} error={errors.coverImageUrl} label="" name="coverImageUrl" type="url" />
          ) : (
            <div className="grid gap-2">
              <input
                accept="image/jpeg,image/png,image/webp"
                className="text-sm text-foreground file:mr-2 file:rounded file:border-0 file:bg-accent file:px-2 file:py-1 file:text-xs file:text-white"
                disabled={coverUploading || !event?.id}
                onChange={async (e) => {
                  const file = e.target.files?.[0];
                  if (!file || !event?.id) return;
                  setCoverUploadError(null);
                  setCoverUploading(true);
                  try {
                    const fd = new FormData();
                    fd.append("file", file);
                    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${event.id}/cover-image`, { method: "POST", body: fd });
                    if (!res.ok) {
                      const body = await res.json() as { error?: { code?: string } };
                      const code = body?.error?.code;
                      setCoverUploadError(code === "image_invalid_type" ? "Type non supporté (JPEG, PNG ou WebP uniquement)." : code === "image_too_large" ? "Fichier trop volumineux (max 10 Mo)." : "Erreur lors de l'upload.");
                    } else {
                      const body = await res.json() as { data?: AdminEventFormData };
                      setUploadedCoverUrl(body?.data?.coverImageUrl ?? null);
                      if (body.data) {
                        onEventUpdated?.(body.data);
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
              {!event?.id && <span className="text-xs text-muted-foreground">Enregistrez d&apos;abord l&apos;événement avant d&apos;uploader une image.</span>}
            </div>
          )}
        </div>
        <DateTimePicker defaultValue={event?.startsAt} error={errors.startsAt} label="Début" name="startsAt" />
        <DateTimePicker defaultValue={event?.endsAt} error={errors.endsAt} label="Fin" name="endsAt" />
        <EventTextField defaultValue={event?.venue} error={errors.venue} label="Lieu" name="venue" />
        <EventTextField defaultValue={event?.capacity} error={errors.capacity} label="Capacité" min={Math.max(1, event?.confirmedRegistrations ?? 0)} name="capacity" type="number" />
        <DateTimePicker defaultValue={event?.registrationOpensAt} error={errors.registrationOpensAt} label="Ouverture inscriptions" name="registrationOpensAt" />
        <DateTimePicker defaultValue={event?.registrationClosesAt} error={errors.registrationClosesAt} label="Fermeture inscriptions" name="registrationClosesAt" />
      </div>

      <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-4 py-3">
        <div className="grid gap-0.5">
          <span className="text-sm font-semibold text-foreground">Accès public</span>
          <span className="text-xs text-muted-foreground">
            {isPublic ? "Inscriptions ouvertes à tous · checkout HelloAsso visible" : "Accès restreint · protégé par mot de passe"}
          </span>
        </div>
        <button
          aria-checked={isPublic}
          className={[
            "relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2",
            isPublic ? "bg-accent" : "bg-border",
          ].join(" ")}
          onClick={() => setIsPublic((v) => !v)}
          role="switch"
          type="button"
        >
          <span className="sr-only">Accès public</span>
          <span
            className={[
              "pointer-events-none inline-block size-5 rounded-full bg-white shadow-sm ring-0 transition-transform",
              isPublic ? "translate-x-5" : "translate-x-0",
            ].join(" ")}
          />
        </button>
        {isPublic && <input name="isPublic" type="hidden" value="on" />}
      </div>

      <label className="grid gap-2 text-sm font-semibold text-foreground">
        Description
        <textarea
          aria-describedby={errors.description ? descriptionErrorId : undefined}
          aria-invalid={errors.description ? true : undefined}
          className="min-h-28 rounded border border-border bg-background px-3 py-2 outline-none focus:border-accent"
          defaultValue={event?.description}
          name="description"
        />
        {errors.description ? <span className="text-xs text-danger" id={descriptionErrorId}>{errors.description}</span> : null}
      </label>

      <div className="grid gap-2">
        <span className="text-sm font-semibold text-foreground">Galerie photos</span>
        {errors.photoGallery ? <span className="text-xs text-danger" id={photoGalleryErrorId}>{errors.photoGallery}</span> : null}
        <GalleryManager
          error={!!errors.photoGallery}
          errorId={photoGalleryErrorId}
          eventId={event?.id}
          initialItems={event?.photoGallery ?? []}
        />
      </div>

      <div className="flex justify-end">
        <button
          className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={submitting}
          type="submit"
        >
          {submitting ? "Enregistrement..." : mode === "edit" ? "Enregistrer les modifications" : "Créer le brouillon"}
        </button>
      </div>
    </form>
  );
}

function EventTextField({
  defaultValue,
  error,
  label,
  min,
  name,
  type = "text",
}: {
  defaultValue?: number | string;
  error?: string;
  label: string;
  min?: number;
  name: EventField;
  type?: string;
}) {
  const errorId = useId();

  return (
    <label className="grid gap-2 text-sm font-semibold text-foreground">
      {label}
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={error ? true : undefined}
        className="min-h-11 rounded border border-border bg-background px-3 outline-none focus:border-accent"
        defaultValue={defaultValue}
        min={min}
        name={name}
        type={type}
      />
      {error ? <span className="text-xs text-danger" id={errorId}>{error}</span> : null}
    </label>
  );
}

export function fieldErrorsFromPayload(payload: unknown): FieldErrors {
  const details =
    payload && typeof payload === "object" && "error" in payload && typeof (payload as { error: unknown }).error === "object"
      ? ((payload as { error: { details?: unknown } }).error.details ?? {})
      : {};

  return {
    title: firstDetail(details, "title"),
    description: firstDetail(details, "description"),
    coverImageUrl: firstDetail(details, "coverImageUrl"),
    photoGallery: firstDetail(details, "photoGallery"),
    startsAt: firstDetail(details, "startsAt"),
    endsAt: firstDetail(details, "endsAt"),
    venue: firstDetail(details, "venue"),
    capacity: firstDetail(details, "capacity"),
    registrationOpensAt: firstDetail(details, "registrationOpensAt"),
    registrationClosesAt: firstDetail(details, "registrationClosesAt"),
  };
}

function firstDetail(details: unknown, key: EventField): string | undefined {
  if (!details || typeof details !== "object") return undefined;
  const value = (details as Record<string, unknown>)[key];
  return Array.isArray(value) && typeof value[0] === "string" ? value[0] : undefined;
}

export function dateTimeLocalValue(value?: string) {
  if (!value) return undefined;
  return new Date(value).toISOString().slice(0, 16);
}

function localDateTime(value: string) {
  if (value === "") return "";
  try {
    return new Date(value).toISOString();
  } catch {
    return "";
  }
}

function optionalUrl(value: string) {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function textareaUrls(value: string) {
  return value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
}

function GalleryManager({
  error,
  errorId,
  eventId,
  initialItems,
}: {
  error: boolean;
  errorId: string;
  eventId?: string;
  initialItems: string[];
}) {
  const [persistedItems, setPersistedItems] = useState<string[]>(initialItems);
  const [localUrls, setLocalUrls] = useState<string[]>([]);
  const [urlInput, setUrlInput] = useState("");
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const allItems = [...persistedItems, ...localUrls];

  async function handleUpload(e: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(e.target.files ?? []);
    if (files.length === 0 || !eventId) return;
    setUploadError(null);
    setUploading(true);
    try {
      for (const file of files) {
        const fd = new FormData();
        fd.append("file", file);
        const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/gallery`, { method: "POST", body: fd });
        if (!res.ok) {
          const body = await res.json() as { error?: { code?: string } };
          const code = body?.error?.code;
          setUploadError(
            code === "image_invalid_type" ? "Type non supporté (JPEG, PNG ou WebP)." :
            code === "image_too_large" ? "Fichier trop volumineux (max 10 Mo)." :
            code === "gallery_full" ? "Galerie pleine (max 12 photos)." :
            "Erreur lors de l'upload."
          );
          break;
        }

        const body = await res.json() as { data?: { photoGallery?: string[] } };
        if (body?.data?.photoGallery) {
          setPersistedItems(body.data.photoGallery);
        }
      }
    } catch {
      setUploadError("Erreur réseau lors de l'upload.");
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  async function handleDeletePersisted(index: number) {
    if (!eventId) return;
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/gallery/${index}`, { method: "DELETE" });
      if (res.ok || res.status === 204) {
        setPersistedItems((prev) => prev.filter((_, i) => i !== index));
      }
    } catch {
      // ignore
    }
  }

  function handleAddUrl() {
    const trimmed = urlInput.trim();
    if (!trimmed) return;
    setLocalUrls((prev) => [...prev, trimmed]);
    setUrlInput("");
  }

  return (
    <div className="grid gap-3">
      <input
        aria-describedby={error ? errorId : undefined}
        name="photoGallery"
        type="hidden"
        value={allItems.join("\n")}
      />

      {allItems.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {persistedItems.map((url, i) => (
            <div className="relative" key={`p-${i}`}>
              <div
                aria-label=""
                className="h-20 w-20 rounded border border-border bg-cover bg-center bg-no-repeat"
                role="img"
                style={{ backgroundImage: `url("${url}")` }}
              />
              <button
                className="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-danger text-xs text-white"
                onClick={() => void handleDeletePersisted(i)}
                type="button"
              >
                ×
              </button>
            </div>
          ))}
          {localUrls.map((url, i) => (
            <div className="relative" key={`l-${i}`}>
              <div
                aria-label=""
                className="h-20 w-20 rounded border border-border bg-cover bg-center bg-no-repeat"
                role="img"
                style={{ backgroundImage: `url("${url}")` }}
              />
              <button
                className="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-danger text-xs text-white"
                onClick={() => setLocalUrls((prev) => prev.filter((_, j) => j !== i))}
                type="button"
              >
                ×
              </button>
            </div>
          ))}
        </div>
      )}

      <div className="flex gap-2">
        <input
          className="min-h-9 flex-1 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
          onChange={(e) => setUrlInput(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); handleAddUrl(); } }}
          placeholder="https://cdn.archilan.fr/events/photo-1.webp"
          type="url"
          value={urlInput}
        />
        <button
          className="rounded border border-border px-3 text-sm text-foreground transition-colors hover:border-accent"
          onClick={handleAddUrl}
          type="button"
        >
          Ajouter
        </button>
      </div>

      <div className="grid gap-1">
        <input
          accept="image/jpeg,image/png,image/webp"
          className="text-sm text-foreground file:mr-2 file:rounded file:border-0 file:bg-accent file:px-2 file:py-1 file:text-xs file:text-white"
          disabled={uploading || !eventId}
          onChange={handleUpload}
          multiple
          type="file"
        />
        {uploading && <span className="text-xs text-muted-foreground">Upload en cours…</span>}
        {uploadError && <span className="text-xs text-danger">{uploadError}</span>}
        {!eventId && <span className="text-xs text-muted-foreground">Enregistrez d&apos;abord l&apos;événement avant d&apos;uploader des photos.</span>}
      </div>
    </div>
  );
}
