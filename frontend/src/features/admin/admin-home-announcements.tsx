"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Eye, ImageOff, Megaphone, Pencil, Plus, Trash2 } from "lucide-react";
import { Dialog as RadixDialog } from "radix-ui";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import {
  deleteHomeAnnouncement,
  fetchAdminHomeAnnouncements,
  removeHomeAnnouncementImage,
  saveHomeAnnouncement,
  uploadHomeAnnouncementImage,
  type AdminHomeAnnouncement,
  type AdminHomeAnnouncementStatus,
  type HomeAnnouncement,
} from "@/features/content/home-announcement-api";
import { HomeAnnouncementContent } from "@/features/content/home-announcement-modal";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";

export const ADMIN_HOME_ANNOUNCEMENTS_KEY = ["admin-home-announcements"] as const;

const STATUS_LABELS: Record<AdminHomeAnnouncementStatus, { label: string; className: string }> = {
  upcoming: { label: "À venir", className: "border-accent/40 text-accent-text" },
  running: { label: "En cours", className: "border-success/40 text-success" },
  ended: { label: "Terminée", className: "border-border text-muted-foreground" },
};

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

const DATE_FORMAT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short" });

/** A date and a time picked in the browser (the admin's, Paris) to the API's ISO date; null when incomplete. */
export function isoFrom(date: string, time: string): string | null {
  if (date === "" || time === "") return null;
  const parsed = new Date(`${date}T${time}`);
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString().replace(/\.\d{3}Z$/, "+00:00");
}

/** The API's ISO date to the `date` and `time` inputs, in the browser's time. */
export function dateAndTimeOf(iso: string): { date: string; time: string } {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
}

/** Story 10.14: the announcements opened as a modal on the home page. */
export function AdminHomeAnnouncements() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ADMIN_HOME_ANNOUNCEMENTS_KEY,
    queryFn: fetchAdminHomeAnnouncements,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const [editing, setEditing] = useState<AdminHomeAnnouncement | "new" | null>(null);
  const [previewing, setPreviewing] = useState<HomeAnnouncement | null>(null);
  const [deleting, setDeleting] = useState<AdminHomeAnnouncement | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ADMIN_HOME_ANNOUNCEMENTS_KEY });
  }

  async function confirmDelete(): Promise<void> {
    if (deleting === null) return;
    setPending(true);
    const error = await deleteHomeAnnouncement(deleting.id);
    setPending(false);
    setDeleteError(error);
    if (error === null) {
      setDeleting(null);
      await refresh();
    }
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-bold text-foreground">Annonces de l&apos;accueil</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Une annonce s&apos;ouvre en fenêtre sur la page d&apos;accueil entre ses deux dates, une fois par visiteur. Une seule à la fois : les
            dates de deux annonces ne se chevauchent pas.
          </p>
        </div>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouvelle annonce
        </button>
      </header>

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && data === null ? <p className="text-sm text-danger">Impossible de charger les annonces.</p> : null}
      {data?.length === 0 ? (
        <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
          <Megaphone aria-hidden className="size-5" />
          Aucune annonce pour l&apos;instant.
        </p>
      ) : null}
      {data !== undefined && data !== null && data.length > 0 ? (
        <ul className="grid gap-3">
          {data.map((announcement) => (
            <AnnouncementRow
              announcement={announcement}
              key={announcement.id}
              onDelete={() => {
                setDeleteError(null);
                setDeleting(announcement);
              }}
              onEdit={() => setEditing(announcement)}
              onPreview={() => setPreviewing(announcement)}
            />
          ))}
        </ul>
      ) : null}

      <Dialog
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
        open={editing !== null}
        size="wide"
        title={editing === "new" ? "Nouvelle annonce" : "Modifier l'annonce"}
        variant="side"
      >
        {editing !== null ? (
          <AnnouncementForm
            current={editing === "new" ? null : editing}
            onPreview={setPreviewing}
            onSaved={async () => {
              await refresh();
              setEditing(null);
            }}
          />
        ) : null}
      </Dialog>

      <RadixDialog.Root
        onOpenChange={(open) => {
          if (!open) setPreviewing(null);
        }}
        open={previewing !== null}
      >
        {previewing !== null ? <HomeAnnouncementContent announcement={previewing} onFollow={() => setPreviewing(null)} /> : null}
      </RadixDialog.Root>

      <ConfirmDialog
        confirmLabel="Supprimer"
        description={deleting !== null ? `« ${deleting.title} » ne s'affichera plus sur l'accueil.` : undefined}
        icon={Trash2}
        onConfirm={() => void confirmDelete()}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        open={deleting !== null}
        pending={pending}
        title="Supprimer l'annonce ?"
        tone="danger"
      >
        {deleteError !== null ? <p className="text-sm text-danger">{deleteError}</p> : null}
      </ConfirmDialog>
    </section>
  );
}

function AnnouncementRow({
  announcement,
  onEdit,
  onPreview,
  onDelete,
}: {
  announcement: AdminHomeAnnouncement;
  onEdit: () => void;
  onPreview: () => void;
  onDelete: () => void;
}) {
  const status = STATUS_LABELS[announcement.status];

  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[1fr_auto] sm:items-center">
      <div className="grid min-w-0 gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-heading text-base font-semibold text-foreground">{announcement.title}</h2>
          <span className={cn("rounded-full border px-2 py-0.5 text-xs font-semibold", status.className)}>{status.label}</span>
        </div>
        <p className="text-sm text-muted-foreground">
          Du {DATE_FORMAT.format(new Date(announcement.startsAt))} au {DATE_FORMAT.format(new Date(announcement.endsAt))}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {announcement.ctaLabel} → {announcement.ctaUrl}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        <button className={buttonVariants({ variant: "ghost" })} onClick={onPreview} type="button">
          <Eye aria-hidden className="size-4" />
          Aperçu
        </button>
        <button className={buttonVariants({ variant: "secondary" })} onClick={onEdit} type="button">
          <Pencil aria-hidden className="size-4" />
          Modifier
        </button>
        <button aria-label={`Supprimer ${announcement.title}`} className={cn(buttonVariants({ variant: "ghost" }), "hover:text-danger")} onClick={onDelete} type="button">
          <Trash2 aria-hidden className="size-4" />
        </button>
      </div>
    </li>
  );
}

function AnnouncementForm({
  current,
  onSaved,
  onPreview,
}: {
  current: AdminHomeAnnouncement | null;
  onSaved: () => Promise<void>;
  onPreview: (announcement: HomeAnnouncement) => void;
}) {
  const start = current !== null ? dateAndTimeOf(current.startsAt) : { date: "", time: "00:01" };
  const end = current !== null ? dateAndTimeOf(current.endsAt) : { date: "", time: "23:59" };
  const [title, setTitle] = useState(current?.title ?? "");
  const [body, setBody] = useState(current?.body ?? "");
  const [ctaLabel, setCtaLabel] = useState(current?.ctaLabel ?? "");
  const [ctaUrl, setCtaUrl] = useState(current?.ctaUrl ?? "");
  const [startDate, setStartDate] = useState(start.date);
  const [startTime, setStartTime] = useState(start.time);
  const [endDate, setEndDate] = useState(end.date);
  const [endTime, setEndTime] = useState(end.time);
  const [file, setFile] = useState<{ file: File; url: string } | null>(null);
  const [dropImage, setDropImage] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const startsAt = isoFrom(startDate, startTime);
  const endsAt = isoFrom(endDate, endTime);
  const valid = title.trim() !== "" && ctaLabel.trim() !== "" && ctaUrl.trim() !== "" && startsAt !== null && endsAt !== null;
  const imageUrl = file?.url ?? (dropImage ? null : (current?.imageUrl ?? null));

  async function submit(): Promise<void> {
    if (startsAt === null || endsAt === null) return;
    setPending(true);
    setError(null);
    const saved = await saveHomeAnnouncement({ title, body, ctaLabel, ctaUrl, startsAt, endsAt }, current?.id ?? null);
    let failure = saved.ok ? null : saved.message;
    if (saved.ok && file !== null) {
      failure = await uploadHomeAnnouncementImage(saved.id, file.file);
    } else if (saved.ok && dropImage && current !== null && current.imageUrl !== null) {
      failure = await removeHomeAnnouncementImage(saved.id);
    }
    setPending(false);
    if (failure !== null) {
      setError(failure);
      return;
    }
    await onSaved();
  }

  return (
    <form
      className="flex min-h-0 flex-1 flex-col"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <DialogBody>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Titre ({title.length}/120)</span>
          <input className={fieldClass} maxLength={120} onChange={(e) => setTitle(e.target.value)} placeholder="Les inscriptions à l'ArchiLAN 4 sont ouvertes !" required type="text" value={title} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Texte, facultatif ({body.length}/600)</span>
          <textarea className={cn(fieldClass, "min-h-28 py-2")} maxLength={600} onChange={(e) => setBody(e.target.value)} value={body} />
          <span className="text-xs text-muted-foreground">Markdown : **gras**, *italique*, listes, liens.</span>
        </label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Bouton</span>
            <input className={fieldClass} maxLength={40} onChange={(e) => setCtaLabel(e.target.value)} placeholder="Je m'inscris" required type="text" value={ctaLabel} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Lien du bouton</span>
            <input className={fieldClass} maxLength={500} onChange={(e) => setCtaUrl(e.target.value)} placeholder="/evenements/archilan-4" required type="text" value={ctaUrl} />
            <span className="text-xs text-muted-foreground">Une page du site (/…) ou une adresse https://</span>
          </label>
        </div>
        <fieldset className="grid gap-3 sm:grid-cols-2">
          <legend className="sr-only">Période d&apos;affichage</legend>
          <div className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Début</span>
            <div className="flex gap-2">
              <input aria-label="Date de début" className={fieldClass} onChange={(e) => setStartDate(e.target.value)} required type="date" value={startDate} />
              <input aria-label="Heure de début" className={cn(fieldClass, "w-28")} onChange={(e) => setStartTime(e.target.value)} required type="time" value={startTime} />
            </div>
          </div>
          <div className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Fin</span>
            <div className="flex gap-2">
              <input aria-label="Date de fin" className={fieldClass} onChange={(e) => setEndDate(e.target.value)} required type="date" value={endDate} />
              <input aria-label="Heure de fin" className={cn(fieldClass, "w-28")} onChange={(e) => setEndTime(e.target.value)} required type="time" value={endTime} />
            </div>
          </div>
        </fieldset>
        <div className="grid gap-2 text-sm">
          <span className="font-medium text-foreground">Image, facultative (JPEG, PNG ou WebP, 10 Mo max, affichée en 16/9)</span>
          <input
            accept="image/jpeg,image/png,image/webp"
            aria-label="Image de l'annonce"
            className="text-sm text-muted-foreground file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-foreground"
            onChange={(e) => {
              const picked = e.target.files?.[0];
              if (file !== null) URL.revokeObjectURL(file.url);
              setFile(picked !== undefined ? { file: picked, url: URL.createObjectURL(picked) } : null);
            }}
            type="file"
          />
          {imageUrl !== null ? (
            <div className="flex items-center gap-3">
              {/* eslint-disable-next-line @next/next/no-img-element -- a local preview (blob URL) next/image cannot load */}
              <img alt="" className="aspect-[16/9] w-40 rounded-lg border border-border object-cover" src={imageUrl} />
              <button
                className={cn(buttonVariants({ variant: "ghost" }), "hover:text-danger")}
                onClick={() => {
                  if (file !== null) URL.revokeObjectURL(file.url);
                  setFile(null);
                  setDropImage(true);
                }}
                type="button"
              >
                <ImageOff aria-hidden className="size-4" />
                Retirer l&apos;image
              </button>
            </div>
          ) : null}
        </div>
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button
          className={buttonVariants({ variant: "ghost" })}
          disabled={!valid}
          onClick={() => {
            if (startsAt !== null && endsAt !== null) {
              onPreview({ id: current?.id ?? "preview", title: title.trim(), body: body.trim(), imageUrl, ctaLabel: ctaLabel.trim(), ctaUrl: ctaUrl.trim(), startsAt, endsAt });
            }
          }}
          type="button"
        >
          <Eye aria-hidden className="size-4" />
          Aperçu
        </button>
        <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
          {current === null ? "Créer l'annonce" : "Enregistrer"}
        </button>
      </DialogFooter>
    </form>
  );
}
