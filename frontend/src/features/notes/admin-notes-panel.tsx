"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, ChevronDown, ChevronRight, History, Lock, MessageSquare, Pencil, RotateCcw, StickyNote } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";
import {
  adminNotesKey,
  editAdminNote,
  fetchAdminNotes,
  fetchNoteRevisions,
  followUpAdminNote,
  NOTE_MAX_LENGTH,
  turnAdminNote,
  writeAdminNote,
  type AdminNote,
  type NoteAuthor,
  type NoteFollowUp,
  type NoteTargetType,
} from "./admin-notes-api";

const fieldClass = "w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none";

export function authorName(author: NoteAuthor): string {
  return author.displayName ?? "Compte supprimé";
}

/** How a follow-up reads in the timeline: a change of state says so, a comment is just its author. */
export function followUpLabel(followUp: NoteFollowUp): string {
  const name = authorName(followUp.author);
  if (followUp.kind === "settled") return `${name} a réglé la note`;
  if (followUp.kind === "reopened") return `${name} a rouvert la note`;
  return name;
}

export function formatNoteDate(value: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Paris" }).format(new Date(value));
}

type Props = { targetType: NoteTargetType; targetId: string };

/**
 * Story 46.2: the internal notes of a target, with their timeline. Knows nothing of the target itself, so the next
 * target (a user account) reuses it as is. Only admins reach the screens that show it.
 */
export function AdminNotesPanel({ targetType, targetId }: Props) {
  const queryClient = useQueryClient();
  const key = adminNotesKey(targetType, targetId);
  const { data: notes, isPending } = useQuery({
    queryKey: key,
    queryFn: () => fetchAdminNotes(targetType, targetId),
    staleTime: DEFAULT_STALE_TIME,
  });

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: key });
  }

  return (
    <section aria-labelledby="admin-notes-title" className="grid gap-4 border border-border bg-surface p-5 sm:p-6">
      <header className="grid gap-1">
        <div className="flex items-center gap-2">
          <StickyNote aria-hidden="true" className="size-4 text-accent-text" />
          <h2 className="font-heading text-lg font-semibold text-foreground" id="admin-notes-title">
            Notes internes
          </h2>
        </div>
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Lock aria-hidden="true" className="size-3" />
          Visibles des admins uniquement, jamais du membre.
        </p>
      </header>

      <NewNoteForm onWrite={(body) => writeAdminNote(targetType, targetId, body)} onWritten={refresh} />

      <NoteList isPending={isPending} notes={notes} onChanged={refresh} />
    </section>
  );
}

type NoteListProps = { isPending: boolean; notes: AdminNote[] | null | undefined; onChanged: () => Promise<void> };

export function NoteList({ isPending, notes, onChanged }: NoteListProps) {
  if (isPending) return <p className="text-sm text-muted-foreground">Chargement des notes...</p>;
  if (notes === null || notes === undefined) {
    return (
      <p className="text-sm text-danger" role="alert">
        Les notes n&apos;ont pas pu être chargées.
      </p>
    );
  }
  if (notes.length === 0) return <p className="text-sm text-muted-foreground">Aucune note.</p>;

  return (
    <ol className="grid gap-3">
      {notes.map((note) => (
        <li key={note.id}>
          <NoteCard note={note} onChanged={onChanged} />
        </li>
      ))}
    </ol>
  );
}

function NewNoteForm({ onWrite, onWritten }: { onWrite: (body: string) => Promise<string | null>; onWritten: () => Promise<void> }) {
  const [body, setBody] = useState("");

  return (
    <TextForm
      label="Nouvelle note"
      maxLength={NOTE_MAX_LENGTH}
      placeholder="Ex. : a payé en espèces, à valider dans HelloAsso."
      required
      submitLabel="Ajouter la note"
      value={body}
      onChange={setBody}
      onSubmit={async () => {
        const error = await onWrite(body);
        if (error === null) {
          setBody("");
          await onWritten();
        }
        return error;
      }}
    />
  );
}

type Mode = "followUp" | "turn" | "edit" | null;

export function NoteCard({ note, onChanged }: { note: AdminNote; onChanged: () => Promise<void> }) {
  const settled = note.status === "settled";
  const [expanded, setExpanded] = useState(!settled);
  const [mode, setMode] = useState<Mode>(null);
  const [text, setText] = useState("");
  const [historyOpen, setHistoryOpen] = useState(false);

  function open(next: Mode): void {
    setMode(next);
    setText(next === "edit" ? note.body : "");
  }

  async function submit(): Promise<string | null> {
    const error =
      mode === "edit" ? await editAdminNote(note.id, text) : mode === "followUp" ? await followUpAdminNote(note.id, text) : await turnAdminNote(note.id, settled ? "reopen" : "settle", text);
    if (error === null) {
      setMode(null);
      setText("");
      await onChanged();
    }
    return error;
  }

  if (!expanded) {
    return (
      <button
        aria-expanded={false}
        className="flex w-full items-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-left text-sm text-muted-foreground opacity-75 hover:opacity-100"
        onClick={() => {
          setExpanded(true);
        }}
        type="button"
      >
        <ChevronRight aria-hidden="true" className="size-4 shrink-0" />
        <CheckCircle2 aria-hidden="true" className="size-4 shrink-0 text-success" />
        <span className="shrink-0 font-semibold">Réglée</span>
        <span className="truncate">{note.body}</span>
      </button>
    );
  }

  return (
    <article className={cn("grid gap-3 rounded-lg border bg-background p-4", settled ? "border-border opacity-80" : "border-accent/50")}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground">
          <span className="font-semibold text-foreground">{authorName(note.author)}</span>, {formatNoteDate(note.createdAt)}
          {note.edited ? " (modifiée)" : null}
        </p>
        {settled ? (
          <button
            aria-expanded
            className="inline-flex items-center gap-1 text-xs font-semibold text-success"
            onClick={() => {
              setExpanded(false);
            }}
            type="button"
          >
            <ChevronDown aria-hidden="true" className="size-3" />
            Réglée
          </button>
        ) : (
          <span className="rounded border border-accent/40 bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent-text">Ouverte</span>
        )}
      </div>

      <p className="whitespace-pre-wrap break-words text-sm text-foreground">{note.body}</p>

      {note.followUps.length > 0 ? (
        <ol className="grid gap-2 border-l-2 border-border pl-3">
          {note.followUps.map((followUp) => (
            <li className="grid gap-0.5" key={followUp.id}>
              <p className="text-xs text-muted-foreground">
                <span className={cn("font-semibold", followUp.kind === "comment" ? "text-foreground" : followUp.kind === "settled" ? "text-success" : "text-accent-text")}>
                  {followUpLabel(followUp)}
                </span>
                , {formatNoteDate(followUp.createdAt)}
              </p>
              {followUp.body !== "" ? <p className="whitespace-pre-wrap break-words text-sm text-foreground">{followUp.body}</p> : null}
            </li>
          ))}
        </ol>
      ) : null}

      {mode === null ? (
        <div className="flex flex-wrap gap-2">
          <button className={buttonVariants({ variant: "secondary" })} onClick={() => open("followUp")} type="button">
            <MessageSquare aria-hidden="true" className="size-4" />
            Suivre
          </button>
          <button className={buttonVariants({ variant: "secondary" })} onClick={() => open("turn")} type="button">
            {settled ? <RotateCcw aria-hidden="true" className="size-4" /> : <CheckCircle2 aria-hidden="true" className="size-4" />}
            {settled ? "Rouvrir" : "Régler"}
          </button>
          <button className={buttonVariants({ variant: "ghost" })} onClick={() => open("edit")} type="button">
            <Pencil aria-hidden="true" className="size-4" />
            Modifier
          </button>
          {note.edited ? (
            <button
              aria-expanded={historyOpen}
              className={buttonVariants({ variant: "ghost" })}
              onClick={() => {
                setHistoryOpen(!historyOpen);
              }}
              type="button"
            >
              <History aria-hidden="true" className="size-4" />
              Historique
            </button>
          ) : null}
        </div>
      ) : (
        <TextForm
          label={mode === "edit" ? "Modifier la note" : mode === "followUp" ? "Suivi" : settled ? "Rouvrir la note (texte facultatif)" : "Régler la note (texte facultatif)"}
          maxLength={NOTE_MAX_LENGTH}
          required={mode !== "turn"}
          submitLabel={mode === "edit" ? "Enregistrer" : mode === "followUp" ? "Ajouter le suivi" : settled ? "Rouvrir" : "Régler"}
          value={text}
          onCancel={() => {
            setMode(null);
          }}
          onChange={setText}
          onSubmit={submit}
        />
      )}

      {historyOpen ? <RevisionList noteId={note.id} /> : null}
    </article>
  );
}

function RevisionList({ noteId }: { noteId: string }) {
  const { data: revisions, isPending } = useQuery({
    queryKey: ["admin-note-revisions", noteId],
    queryFn: () => fetchNoteRevisions(noteId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (isPending) return <p className="text-xs text-muted-foreground">Chargement de l&apos;historique...</p>;
  if (revisions === null || revisions === undefined) {
    return (
      <p className="text-xs text-danger" role="alert">
        L&apos;historique n&apos;a pas pu être chargé.
      </p>
    );
  }

  return (
    <div className="grid gap-2 rounded-lg border border-border bg-surface p-3">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Versions précédentes</h3>
      <ol className="grid gap-2">
        {revisions.map((revision) => (
          <li className="grid gap-0.5" key={revision.id}>
            <p className="text-xs text-muted-foreground">
              Remplacée par <span className="font-semibold text-foreground">{authorName(revision.editor)}</span>, {formatNoteDate(revision.editedAt)}
            </p>
            <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground line-through decoration-muted-foreground/40">{revision.previousBody}</p>
          </li>
        ))}
      </ol>
    </div>
  );
}

type TextFormProps = {
  label: string;
  maxLength: number;
  placeholder?: string;
  required: boolean;
  submitLabel: string;
  value: string;
  onCancel?: () => void;
  onChange: (value: string) => void;
  onSubmit: () => Promise<string | null>;
};

function TextForm({ label, maxLength, placeholder, required, submitLabel, value, onCancel, onChange, onSubmit }: TextFormProps) {
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  return (
    <form
      className="grid gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        setPending(true);
        void onSubmit().then((result) => {
          setError(result);
          setPending(false);
        });
      }}
    >
      <label className="grid gap-1.5 text-sm">
        <span className="font-medium text-foreground">
          {label} ({value.length}/{maxLength})
        </span>
        <textarea
          className={cn(fieldClass, "min-h-20")}
          disabled={pending}
          maxLength={maxLength}
          onChange={(e) => {
            onChange(e.target.value);
          }}
          placeholder={placeholder}
          required={required}
          value={value}
        />
      </label>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <button className={buttonVariants({ variant: "primary" })} disabled={pending || (required && value.trim() === "")} type="submit">
          {submitLabel}
        </button>
        {onCancel !== undefined ? (
          <button className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={onCancel} type="button">
            Annuler
          </button>
        ) : null}
      </div>
    </form>
  );
}
