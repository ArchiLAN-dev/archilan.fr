"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, BookOpen, ExternalLink, Pencil, Plus, Trash2 } from "lucide-react";

import { MarkdownEditor } from "@/components/markdown/markdown-editor";
import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { movedIds } from "@/features/admin/admin-partners";
import {
  deleteHelpGuide,
  fetchAdminHelpGuides,
  HELP_GUIDE_LIMITS,
  helpGuidePath,
  reorderHelpGuides,
  saveHelpGuide,
  type AdminHelpGuide,
} from "@/features/content/help-guides-api";
import { slugify } from "@/features/weekly-runs/slugify";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";

export const ADMIN_HELP_GUIDES_KEY = ["admin-help-guides"] as const;

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

/** The address a new guide gets from its title, while the admin has not written one. */
export function suggestedSlug(title: string): string {
  return slugify(title).slice(0, HELP_GUIDE_LIMITS.slug).replace(/-+$/, "");
}

/** Story 53.5: the help guides, written and ordered by the orga. */
export function AdminHelpGuides() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ADMIN_HELP_GUIDES_KEY,
    queryFn: fetchAdminHelpGuides,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const [editing, setEditing] = useState<AdminHelpGuide | "new" | null>(null);
  const [deleting, setDeleting] = useState<AdminHelpGuide | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ADMIN_HELP_GUIDES_KEY });
  }

  async function confirmDelete(): Promise<void> {
    if (deleting === null) return;
    setPending(true);
    const error = await deleteHelpGuide(deleting.id);
    setPending(false);
    setDeleteError(error);
    if (error === null) {
      setDeleting(null);
      await refresh();
    }
  }

  async function move(guides: AdminHelpGuide[], index: number, delta: -1 | 1): Promise<void> {
    setPending(true);
    const error = await reorderHelpGuides(movedIds(guides.map((guide) => guide.id), index, delta));
    setPending(false);
    setListError(error);
    await refresh();
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-bold text-foreground">Guides d&apos;aide</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Les guides affichés sur <span className="font-mono">/aide</span> et dans l&apos;espace des inscrits, dans cet ordre. Un brouillon
            n&apos;est visible nulle part. Le guide d&apos;installation d&apos;Archipelago se règle dans{" "}
            <Link className="text-accent-text hover:text-accent-text-hover" href="/admin/aide-archipelago">
              Apworlds, Aide Archipelago
            </Link>
            .
          </p>
        </div>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouveau guide
        </button>
      </header>

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && data === null ? <p className="text-sm text-danger">Impossible de charger les guides.</p> : null}
      {data?.length === 0 ? (
        <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
          <BookOpen aria-hidden className="size-5" />
          Aucun guide pour l&apos;instant.
        </p>
      ) : null}
      {listError !== null ? (
        <p className="text-sm text-danger" role="alert">
          {listError}
        </p>
      ) : null}
      {data !== undefined && data !== null && data.length > 0 ? (
        <ul className="grid gap-3">
          {data.map((guide, index) => (
            <HelpGuideRow
              guide={guide}
              isFirst={index === 0}
              isLast={index === data.length - 1}
              key={guide.id}
              onDelete={() => {
                setDeleteError(null);
                setDeleting(guide);
              }}
              onEdit={() => setEditing(guide)}
              onMove={(delta) => void move(data, index, delta)}
              pending={pending}
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
        title={editing === "new" ? "Nouveau guide" : "Modifier le guide"}
        variant="side"
      >
        {editing !== null ? (
          <HelpGuideForm
            current={editing === "new" ? null : editing}
            onSaved={async () => {
              await refresh();
              setEditing(null);
            }}
          />
        ) : null}
      </Dialog>

      <ConfirmDialog
        confirmLabel="Supprimer"
        description={deleting !== null ? `Le brouillon « ${deleting.title} » sera supprimé.` : undefined}
        icon={Trash2}
        onConfirm={() => void confirmDelete()}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        open={deleting !== null}
        pending={pending}
        title="Supprimer le guide ?"
        tone="danger"
      >
        {deleteError !== null ? <p className="text-sm text-danger">{deleteError}</p> : null}
      </ConfirmDialog>
    </section>
  );
}

type RowProps = {
  guide: AdminHelpGuide;
  isFirst: boolean;
  isLast: boolean;
  pending: boolean;
  onEdit: () => void;
  onMove: (delta: -1 | 1) => void;
  onDelete: () => void;
};

export function HelpGuideRow({ guide, isFirst, isLast, pending, onEdit, onMove, onDelete }: RowProps) {
  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[1fr_auto] sm:items-center">
      <div className="grid min-w-0 gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-heading text-base font-semibold text-foreground">{guide.title}</h2>
          <span
            className={cn(
              "rounded-full border px-2 py-0.5 text-xs font-semibold",
              guide.published ? "border-success/40 text-success" : "border-border text-muted-foreground",
            )}
          >
            {guide.published ? "Publié" : "Brouillon"}
          </span>
        </div>
        <p className="text-sm text-muted-foreground">{guide.summary}</p>
        {guide.published ? (
          <a
            className="inline-flex w-fit items-center gap-1 font-mono text-xs text-accent-text hover:text-accent-text-hover"
            href={helpGuidePath(guide.slug)}
            rel="noopener noreferrer"
            target="_blank"
          >
            {helpGuidePath(guide.slug)}
            <ExternalLink aria-hidden className="size-3" />
          </a>
        ) : (
          <p className="font-mono text-xs text-muted-foreground">{helpGuidePath(guide.slug)}</p>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <button aria-label={`Monter ${guide.title}`} className={buttonVariants({ variant: "ghost" })} disabled={isFirst || pending} onClick={() => onMove(-1)} type="button">
          <ArrowUp aria-hidden className="size-4" />
        </button>
        <button aria-label={`Descendre ${guide.title}`} className={buttonVariants({ variant: "ghost" })} disabled={isLast || pending} onClick={() => onMove(1)} type="button">
          <ArrowDown aria-hidden className="size-4" />
        </button>
        <button className={buttonVariants({ variant: "secondary" })} onClick={onEdit} type="button">
          <Pencil aria-hidden className="size-4" />
          Modifier
        </button>
        {/* A published guide is unpublished first: a link shared on Discord does not vanish by a slip. */}
        <button
          aria-label={`Supprimer ${guide.title}`}
          className={cn(buttonVariants({ variant: "ghost" }), "hover:text-danger")}
          disabled={guide.published}
          onClick={onDelete}
          title={guide.published ? "Dépublie le guide avant de le supprimer" : undefined}
          type="button"
        >
          <Trash2 aria-hidden className="size-4" />
        </button>
      </div>
    </li>
  );
}

type FormProps = {
  current: AdminHelpGuide | null;
  onSaved: () => Promise<void>;
};

function HelpGuideForm({ current, onSaved }: FormProps) {
  const [title, setTitle] = useState(current?.title ?? "");
  // A new guide follows its title until the admin writes its address.
  const [slug, setSlug] = useState<string | null>(current?.slug ?? null);
  const [summary, setSummary] = useState(current?.summary ?? "");
  const [body, setBody] = useState(current?.body ?? "");
  const [published, setPublished] = useState(current?.published ?? false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const effectiveSlug = slug ?? suggestedSlug(title);
  const valid = title.trim() !== "" && effectiveSlug !== "" && summary.trim() !== "" && body.trim() !== "";

  async function submit(): Promise<void> {
    setPending(true);
    setError(null);
    const saved = await saveHelpGuide({ slug: effectiveSlug, title, summary, body, published }, current?.id ?? null);
    setPending(false);
    if (!saved.ok) {
      setError(saved.message);
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
          <span className="font-medium text-foreground">Titre</span>
          <input className={fieldClass} maxLength={HELP_GUIDE_LIMITS.title} onChange={(e) => setTitle(e.target.value)} required type="text" value={title} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Adresse</span>
          <span className="flex items-center gap-1">
            <span className="font-mono text-xs text-muted-foreground">/aide/</span>
            <input
              className={cn(fieldClass, "font-mono")}
              maxLength={HELP_GUIDE_LIMITS.slug}
              onChange={(e) => setSlug(e.target.value)}
              pattern="[a-z0-9]+(-[a-z0-9]+)*"
              required
              type="text"
              value={effectiveSlug}
            />
          </span>
          <span className="text-xs text-muted-foreground">Minuscules, chiffres et tirets. La changer casse les liens déjà partagés.</span>
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">
            Résumé ({summary.length}/{HELP_GUIDE_LIMITS.summary})
          </span>
          <textarea
            className={cn(fieldClass, "min-h-16 py-2")}
            maxLength={HELP_GUIDE_LIMITS.summary}
            onChange={(e) => setSummary(e.target.value)}
            required
            value={summary}
          />
          <span className="text-xs text-muted-foreground">Une ou deux phrases, sur la carte du guide et dans les moteurs de recherche.</span>
        </label>
        <div className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Texte</span>
          <MarkdownEditor maxLength={HELP_GUIDE_LIMITS.body} onChange={setBody} rows={14} value={body} />
        </div>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input checked={published} className="size-4" onChange={(e) => setPublished(e.target.checked)} type="checkbox" />
          Publié
        </label>
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
          {current === null ? "Créer le guide" : "Enregistrer"}
        </button>
      </DialogFooter>
    </form>
  );
}
