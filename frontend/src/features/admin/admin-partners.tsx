"use client";

import { useState } from "react";
import Image from "next/image";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, Handshake, ImageOff, Pencil, Plus, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import {
  deletePartner,
  fetchAdminPartners,
  removePartnerLogo,
  reorderPartners,
  savePartner,
  uploadPartnerLogo,
  type AdminPartner,
} from "@/features/content/partners-api";
import { PartnerScreenLinks } from "@/features/admin/partner-screen-links";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";

export const ADMIN_PARTNERS_KEY = ["admin-partners"] as const;

/** The home page is laid out for this many partners; more only raises a warning. */
export const HOME_PARTNERS_LIMIT = 5;

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

/** The ids with the one at `from` moved by `delta` (-1 up, +1 down); unchanged at the ends. */
export function movedIds(ids: readonly string[], from: number, delta: -1 | 1): string[] {
  const to = from + delta;
  if (from < 0 || from >= ids.length || to < 0 || to >= ids.length) return [...ids];
  const next = [...ids];
  [next[from], next[to]] = [next[to], next[from]];
  return next;
}

export function partnerStateLabel(partner: Pick<AdminPartner, "active" | "visible">): string {
  if (partner.visible) return "Affiché";
  return partner.active ? "Masqué : pas de logo" : "Masqué";
}

/** The host of a partner's link, without `www.`; the raw text when it does not parse. */
export function displayHost(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return url;
  }
}

export function tooManyVisible(partners: readonly Pick<AdminPartner, "visible">[]): boolean {
  return partners.filter((partner) => partner.visible).length > HOME_PARTNERS_LIMIT;
}

/** Story 45.1: the partners of the association. */
export function AdminPartners() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ADMIN_PARTNERS_KEY,
    queryFn: fetchAdminPartners,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const [editing, setEditing] = useState<AdminPartner | "new" | null>(null);
  const [deleting, setDeleting] = useState<AdminPartner | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ADMIN_PARTNERS_KEY });
  }

  async function confirmDelete(): Promise<void> {
    if (deleting === null) return;
    setPending(true);
    const error = await deletePartner(deleting.id);
    setPending(false);
    setDeleteError(error);
    if (error === null) {
      setDeleting(null);
      await refresh();
    }
  }

  async function move(partners: AdminPartner[], index: number, delta: -1 | 1): Promise<void> {
    setPending(true);
    const error = await reorderPartners(movedIds(partners.map((partner) => partner.id), index, delta));
    setPending(false);
    setListError(error);
    await refresh();
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-bold text-foreground">Partenaires</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Les partenaires de l&apos;association, dans l&apos;ordre où le site les affiche. Un partenaire apparaît sur le site une fois affiché et
            avec un logo.
          </p>
        </div>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouveau partenaire
        </button>
      </header>

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && data === null ? <p className="text-sm text-danger">Impossible de charger les partenaires.</p> : null}
      {data?.length === 0 ? (
        <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
          <Handshake aria-hidden className="size-5" />
          Aucun partenaire pour l&apos;instant.
        </p>
      ) : null}
      {data !== undefined && data !== null && tooManyVisible(data) ? (
        <p className="rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm text-foreground" role="status">
          L&apos;accueil est pensé pour {HOME_PARTNERS_LIMIT} partenaires au plus.
        </p>
      ) : null}
      {listError !== null ? (
        <p className="text-sm text-danger" role="alert">
          {listError}
        </p>
      ) : null}
      {data !== undefined && data !== null && data.length > 0 ? (
        <ul className="grid gap-3">
          {data.map((partner, index) => (
            <PartnerRow
              isFirst={index === 0}
              isLast={index === data.length - 1}
              key={partner.id}
              onDelete={() => {
                setDeleteError(null);
                setDeleting(partner);
              }}
              onEdit={() => setEditing(partner)}
              onMove={(delta) => void move(data, index, delta)}
              partner={partner}
              pending={pending}
            />
          ))}
        </ul>
      ) : null}

      {/* Story 45.4: every partner on stream or on the LAN screen, outside any event. */}
      <PartnerScreenLinks eventId={null} />

      <Dialog
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
        open={editing !== null}
        size="wide"
        title={editing === "new" ? "Nouveau partenaire" : "Modifier le partenaire"}
        variant="side"
      >
        {editing !== null ? (
          <PartnerForm
            current={editing === "new" ? null : editing}
            onCreated={refresh}
            onSaved={async () => {
              await refresh();
              setEditing(null);
            }}
          />
        ) : null}
      </Dialog>

      <ConfirmDialog
        confirmLabel="Supprimer"
        description={deleting !== null ? `« ${deleting.name} » disparaîtra du site.` : undefined}
        icon={Trash2}
        onConfirm={() => void confirmDelete()}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        open={deleting !== null}
        pending={pending}
        title="Supprimer le partenaire ?"
        tone="danger"
      >
        {deleteError !== null ? <p className="text-sm text-danger">{deleteError}</p> : null}
      </ConfirmDialog>
    </section>
  );
}

type RowProps = {
  partner: AdminPartner;
  isFirst: boolean;
  isLast: boolean;
  pending: boolean;
  onEdit: () => void;
  onMove: (delta: -1 | 1) => void;
  onDelete: () => void;
};

function PartnerRow({ partner, isFirst, isLast, pending, onEdit, onMove, onDelete }: RowProps) {
  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[auto_1fr_auto] sm:items-center">
      <div className="flex size-16 items-center justify-center rounded-lg border border-border bg-background p-1.5">
        {partner.logoUrl !== null ? (
          <Image alt="" className="max-h-full w-auto object-contain" height={52} src={partner.logoUrl} width={52} />
        ) : (
          <ImageOff aria-hidden className="size-5 text-muted-foreground" />
        )}
      </div>
      <div className="grid min-w-0 gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-heading text-base font-semibold text-foreground">{partner.name}</h2>
          <span
            className={cn(
              "rounded-full border px-2 py-0.5 text-xs font-semibold",
              partner.visible ? "border-success/40 text-success" : "border-border text-muted-foreground",
            )}
          >
            {partnerStateLabel(partner)}
          </span>
        </div>
        {partner.description !== "" ? <p className="text-sm text-muted-foreground">{partner.description}</p> : null}
        <p className="truncate text-xs text-muted-foreground">{displayHost(partner.url)}</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <button aria-label={`Monter ${partner.name}`} className={buttonVariants({ variant: "ghost" })} disabled={isFirst || pending} onClick={() => onMove(-1)} type="button">
          <ArrowUp aria-hidden className="size-4" />
        </button>
        <button aria-label={`Descendre ${partner.name}`} className={buttonVariants({ variant: "ghost" })} disabled={isLast || pending} onClick={() => onMove(1)} type="button">
          <ArrowDown aria-hidden className="size-4" />
        </button>
        <button className={buttonVariants({ variant: "secondary" })} onClick={onEdit} type="button">
          <Pencil aria-hidden className="size-4" />
          Modifier
        </button>
        <button aria-label={`Supprimer ${partner.name}`} className={cn(buttonVariants({ variant: "ghost" }), "hover:text-danger")} onClick={onDelete} type="button">
          <Trash2 aria-hidden className="size-4" />
        </button>
      </div>
    </li>
  );
}

type FormProps = {
  current: AdminPartner | null;
  onCreated: () => Promise<void>;
  onSaved: () => Promise<void>;
};

function PartnerForm({ current, onCreated, onSaved }: FormProps) {
  const [name, setName] = useState(current?.name ?? "");
  const [description, setDescription] = useState(current?.description ?? "");
  const [url, setUrl] = useState(current?.url ?? "");
  const [active, setActive] = useState(current?.active ?? true);
  const [file, setFile] = useState<{ file: File; url: string } | null>(null);
  const [dropLogo, setDropLogo] = useState(false);
  // Set once a creation went through but the logo upload failed: the next submit edits it, never creates a twin.
  const [createdId, setCreatedId] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const id = current?.id ?? createdId;
  const valid = name.trim() !== "" && url.trim() !== "";
  const logoUrl = file?.url ?? (dropLogo ? null : (current?.logoUrl ?? null));

  async function submit(): Promise<void> {
    setPending(true);
    setError(null);
    const saved = await savePartner({ name, description, url, active }, id);
    let failure = saved.ok ? null : saved.message;
    if (saved.ok && file !== null) {
      failure = await uploadPartnerLogo(saved.id, file.file);
    } else if (saved.ok && dropLogo && current !== null && current.logoUrl !== null) {
      failure = await removePartnerLogo(saved.id);
    }
    setPending(false);
    if (failure !== null) {
      if (saved.ok && id === null) {
        setCreatedId(saved.id);
        await onCreated();
      }
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
          <span className="font-medium text-foreground">Nom</span>
          <input className={fieldClass} maxLength={80} onChange={(e) => setName(e.target.value)} required type="text" value={name} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Description courte, facultative ({description.length}/160)</span>
          <textarea className={cn(fieldClass, "min-h-20 py-2")} maxLength={160} onChange={(e) => setDescription(e.target.value)} value={description} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Lien vers son site</span>
          <input className={fieldClass} maxLength={500} onChange={(e) => setUrl(e.target.value)} placeholder="https://" required type="url" value={url} />
          <span className="text-xs text-muted-foreground">Une adresse https://</span>
        </label>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input checked={active} className="size-4" onChange={(e) => setActive(e.target.checked)} type="checkbox" />
          Afficher sur le site
        </label>
        <div className="grid gap-2 text-sm">
          <span className="font-medium text-foreground">Logo</span>
          <span className="text-xs text-muted-foreground">PNG, JPEG ou WebP, 2 Mo max, fond transparent conseillé. Un logo SVG doit être converti en PNG.</span>
          <input
            accept="image/png,image/jpeg,image/webp"
            aria-label="Logo du partenaire"
            className="text-sm text-muted-foreground file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-foreground"
            onChange={(e) => {
              const picked = e.target.files?.[0];
              if (file !== null) URL.revokeObjectURL(file.url);
              setFile(picked !== undefined ? { file: picked, url: URL.createObjectURL(picked) } : null);
            }}
            type="file"
          />
          {logoUrl !== null ? (
            <div className="flex flex-wrap items-center gap-3">
              <LogoPreview url={logoUrl} />
              <button
                className={cn(buttonVariants({ variant: "ghost" }), "hover:text-danger")}
                onClick={() => {
                  if (file !== null) URL.revokeObjectURL(file.url);
                  setFile(null);
                  setDropLogo(true);
                }}
                type="button"
              >
                <ImageOff aria-hidden className="size-4" />
                Retirer le logo
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
        <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
          {id === null ? "Créer le partenaire" : "Enregistrer"}
        </button>
      </DialogFooter>
    </form>
  );
}

/**
 * The logo on a light and a dark tile side by side, whatever the admin's theme: a dark logo on a transparent
 * background vanishes in the dark theme, and the admin must see it before publishing. The two tile colors are fixed
 * on purpose, not theme tokens.
 */
function LogoPreview({ url }: { url: string }) {
  return (
    <div className="flex gap-2">
      {["bg-white", "bg-neutral-900"].map((tile) => (
        <div className={cn("flex h-20 w-32 items-center justify-center rounded-lg border border-border p-2", tile)} key={tile}>
          {/* eslint-disable-next-line @next/next/no-img-element -- a local preview (blob URL) next/image cannot load */}
          <img alt="" className="max-h-full max-w-full object-contain" src={url} />
        </div>
      ))}
    </div>
  );
}
