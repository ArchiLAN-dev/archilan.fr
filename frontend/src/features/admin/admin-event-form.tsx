"use client";

import { useId, useState } from "react";
import type { FormEvent } from "react";

import { DateTimePicker } from "@/components/date-time-picker";

export type EventFormInput = {
  title: string;
  description: string;
  coverImageUrl: string | null;
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
}: {
  event: AdminEventFormData | null;
  mode: "create" | "edit";
  onSubmit: (input: EventFormInput) => Promise<void>;
}) {
  const [errors, setErrors] = useState<FieldErrors>({});
  const [genericError, setGenericError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [isPublic, setIsPublic] = useState(event?.isPublic ?? true);
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
        coverImageUrl: optionalUrl(String(form.get("coverImageUrl") ?? "")),
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
        <EventTextField defaultValue={event?.coverImageUrl ?? ""} error={errors.coverImageUrl} label="URL image de couverture" name="coverImageUrl" type="url" />
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

      <label className="grid gap-2 text-sm font-semibold text-foreground">
        Galerie photos
        <textarea
          aria-describedby={errors.photoGallery ? photoGalleryErrorId : undefined}
          aria-invalid={errors.photoGallery ? true : undefined}
          className="min-h-28 rounded border border-border bg-background px-3 py-2 outline-none focus:border-accent"
          defaultValue={(event?.photoGallery ?? []).join("\n")}
          name="photoGallery"
          placeholder="https://cdn.archilan.fr/events/photo-1.webp"
        />
        {errors.photoGallery ? <span className="text-xs text-danger" id={photoGalleryErrorId}>{errors.photoGallery}</span> : null}
      </label>

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
