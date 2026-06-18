"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Flag, Trash2 } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import {
  deleteComment,
  fetchComments,
  postComment,
  reportComment,
  type ProfileComment,
} from "./community-comments-api";

export function ProfileComments({ slug }: { slug: string }) {
  const { user } = useAuth();
  const [comments, setComments] = useState<ProfileComment[]>([]);
  const [forbidden, setForbidden] = useState(false);
  const [ready, setReady] = useState(false);
  const [body, setBody] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [reported, setReported] = useState<Set<string>>(new Set());

  async function reload() {
    const result = await fetchComments(slug);
    if (result === "forbidden") {
      setForbidden(true);
    } else if (result !== null) {
      setComments(result);
    }
  }

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const result = await fetchComments(slug);
      if (cancelled) return;
      if (result === "forbidden") setForbidden(true);
      else if (result !== null) setComments(result);
      setReady(true);
    })();
    return () => { cancelled = true; };
  }, [slug]);

  if (!ready || forbidden) return null;

  async function handlePost() {
    setError(null);
    if (body.trim() === "") return;
    setBusy(true);
    const result = await postComment(slug, body.trim());
    setBusy(false);
    if (result === "ok") {
      setBody("");
      await reload();
    } else if (result === "rate_limited") {
      setError("Trop de commentaires d'affilée — réessaie dans une minute.");
    } else if (result === "forbidden") {
      setError("Tu dois être adhérent pour commenter ce profil.");
    } else {
      setError("Impossible de publier le commentaire.");
    }
  }

  async function handleDelete(id: string) {
    if (await deleteComment(id)) await reload();
  }

  async function handleReport(id: string) {
    if (await reportComment(id, "inappropriate")) {
      setReported((prev) => new Set(prev).add(id));
    }
  }

  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-lg font-semibold text-foreground">
        Commentaires <span className="text-sm font-normal text-muted-foreground">({comments.length})</span>
      </h2>

      {user ? (
        <div className="grid gap-2">
          <textarea
            className="min-h-20 w-full rounded border border-border bg-background px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
            maxLength={2000}
            onChange={(e) => { setBody(e.target.value); setError(null); }}
            placeholder="Laisse un mot sur ce profil…"
            value={body}
          />
          <div className="flex items-center gap-3">
            <button
              className="inline-flex min-h-9 cursor-pointer items-center rounded bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
              disabled={busy || body.trim() === ""}
              onClick={() => { void handlePost(); }}
              type="button"
            >
              Publier
            </button>
            {error ? <span className="text-xs text-[color:var(--color-danger)]">{error}</span> : null}
          </div>
        </div>
      ) : null}

      {comments.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun commentaire pour l&apos;instant.</p>
      ) : (
        <ul className="grid gap-3" role="list">
          {comments.map((comment) => (
            <li className="flex gap-3 rounded-lg border border-border bg-surface p-3" key={comment.id}>
              <span
                aria-hidden
                className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent/15 text-sm font-bold text-accent-text"
              >
                {comment.author?.avatarUrl ? (
                  // eslint-disable-next-line @next/next/no-img-element -- external avatar
                  <img alt="" className="size-full object-cover" src={comment.author.avatarUrl} />
                ) : (
                  (comment.author?.displayName ?? comment.author?.slug ?? "?").slice(0, 1).toUpperCase()
                )}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2">
                  {comment.author ? (
                    <Link className="text-sm font-semibold text-foreground hover:text-accent-text" href={`/joueurs/${comment.author.slug}`}>
                      {comment.author.displayName ?? comment.author.slug}
                    </Link>
                  ) : (
                    <span className="text-sm font-semibold text-muted-foreground">Membre</span>
                  )}
                  <time className="text-xs text-muted-foreground" dateTime={comment.createdAt}>
                    {relativeTime(comment.createdAt)}
                  </time>
                </div>
                <p className="mt-0.5 whitespace-pre-line break-words text-sm text-muted-foreground">{comment.body}</p>
              </div>
              <div className="flex shrink-0 items-start gap-1">
                {user && !reported.has(comment.id) ? (
                  <button
                    aria-label="Signaler"
                    className="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:text-[color:var(--color-danger)]"
                    onClick={() => { void handleReport(comment.id); }}
                    type="button"
                  >
                    <Flag aria-hidden className="size-3.5" />
                  </button>
                ) : null}
                {comment.canDelete ? (
                  <button
                    aria-label="Supprimer"
                    className="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)]"
                    onClick={() => { void handleDelete(comment.id); }}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-3.5" />
                  </button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function relativeTime(iso: string): string {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diff = Date.now() - ts;
  if (diff < 60_000) return "à l'instant";
  if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
  if (diff < 86_400_000) return `il y a ${Math.floor(diff / 3_600_000)} h`;
  return `il y a ${Math.floor(diff / 86_400_000)} j`;
}
