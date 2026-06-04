"use client";

import { FilePenLine, FileText, Pencil, PlusCircle, ShieldAlert } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { useEffect, useMemo, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type AdminPost = {
  id: string;
  slug: string;
  title: string;
  type: "news" | "recap" | "announcement";
  status: "draft" | "published";
  excerpt: string;
  body: string[];
  readingTime: string;
  coverImageUrl: string | null;
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
};

type DashboardState =
  | { kind: "loading" }
  | { kind: "ready"; posts: AdminPost[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

const typeLabels: Record<AdminPost["type"], string> = {
  news: "Actualité",
  recap: "Récap",
  announcement: "Annonce",
};

export function AdminContentDashboard() {
  const [state, setState] = useState<DashboardState>({ kind: "loading" });
  const [actionError, setActionError] = useState<string | null>(null);
  const [actioningId, setActioningId] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState<AdminPost["type"] | "all">("all");
  const [statusFilter, setStatusFilter] = useState<"all" | "draft" | "published">("all");

  useEffect(() => {
    const controller = new AbortController();

    async function loadPosts() {
      setState({ kind: "loading" });
      setActionError(null);

      try {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/posts`, {
          signal: controller.signal,
        });

        if (response.status === 401 || response.status === 403) {
          setState({ kind: "denied", message: "Accès réservé aux admins ArchiLAN." });
          return;
        }

        if (!response.ok) {
          setState({ kind: "error", message: "Impossible de charger les articles." });
          return;
        }

        const payload: unknown = await response.json();
        const posts = isPostListPayload(payload) ? payload.data : [];
        setState({ kind: "ready", posts });
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return;
        setState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    }

    void loadPosts();

    return () => controller.abort();
  }, [refreshKey]);

  const filteredPosts = useMemo(() => {
    if (state.kind !== "ready") return [];
    return state.posts.filter((post) => {
      const q = search.toLowerCase();
      const matchesSearch = !search || post.title.toLowerCase().includes(q) || post.slug.includes(q);
      const matchesType = typeFilter === "all" || post.type === typeFilter;
      const matchesStatus = statusFilter === "all" || post.status === statusFilter;
      return matchesSearch && matchesType && matchesStatus;
    });
  }, [state, search, typeFilter, statusFilter]);

  async function togglePublish(post: AdminPost) {
    const action = post.status === "published" ? "unpublish" : "publish";
    setActioningId(post.id);
    setActionError(null);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/admin/posts/${post.id}/${action}`, {
        method: "POST",
      });

      if (!response.ok) {
        setActionError("L'action a échoué. Veuillez réessayer.");
        return;
      }

      setRefreshKey((k) => k + 1);
    } catch {
      setActionError("Impossible de contacter l'API.");
    } finally {
      setActioningId(null);
    }
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header className="grid gap-3">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice
        </p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Gestion du contenu
            </h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Créez, éditez, publiez et dépubliez les actualités et récaps ArchiLAN.
            </p>
          </div>
          <Link
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/admin/actualites/nouveau"
          >
            <PlusCircle aria-hidden="true" className="size-4" />
            Nouveau post
          </Link>
        </div>
      </header>

      {actionError ? (
        <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
          {actionError}
        </p>
      ) : null}

      <div className="flex flex-wrap gap-3">
        <input
          className="min-h-10 flex-1 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
          placeholder="Rechercher par titre ou slug…"
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value as AdminPost["type"] | "all")}
        >
          <option value="all">Tous types</option>
          <option value="news">Actualité</option>
          <option value="recap">Récap</option>
          <option value="announcement">Annonce</option>
        </select>
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "all" | "draft" | "published")}
        >
          <option value="all">Tous statuts</option>
          <option value="published">Publié</option>
          <option value="draft">Brouillon</option>
        </select>
      </div>

      {state.kind === "ready" ? (
        <p className="text-xs text-muted-foreground">
          {filteredPosts.length} article{filteredPosts.length !== 1 ? "s" : ""}
          {state.posts.length !== filteredPosts.length ? ` sur ${state.posts.length}` : ""}
        </p>
      ) : null}

      <ContentBody
        actioningId={actioningId}
        onTogglePublish={(post) => void togglePublish(post)}
        posts={filteredPosts}
        state={state}
      />
    </section>
  );
}

function ContentBody({
  actioningId,
  onTogglePublish,
  posts,
  state,
}: {
  actioningId: string | null;
  onTogglePublish: (post: AdminPost) => void;
  posts: AdminPost[];
  state: DashboardState;
}) {
  if (state.kind === "loading") {
    return (
      <div aria-busy="true" className="grid gap-3 border border-border bg-surface p-5">
        <div className="h-5 w-48 bg-surface-2" />
        <div className="h-12 bg-surface-2" />
        <div className="h-12 bg-surface-2" />
        <div className="h-12 bg-surface-2" />
      </div>
    );
  }

  if (state.kind === "denied") {
    return (
      <EmptyPanel
        icon={<ShieldAlert aria-hidden="true" className="size-8 text-danger" />}
        title="Accès admin requis"
      >
        {state.message}
      </EmptyPanel>
    );
  }

  if (state.kind === "error") {
    return (
      <EmptyPanel
        icon={<ShieldAlert aria-hidden="true" className="size-8 text-danger" />}
        title="Contenu indisponible"
      >
        {state.message}
      </EmptyPanel>
    );
  }

  if (posts.length === 0) {
    return (
      <EmptyPanel
        icon={<FileText aria-hidden="true" className="size-8 text-accent-text" />}
        title="Aucun article"
      >
        {state.kind === "ready" ? "Aucun article ne correspond aux filtres." : "Commencez par créer votre premier post."}
      </EmptyPanel>
    );
  }

  return (
    <div className="overflow-x-auto border border-border bg-surface">
      <table className="w-full min-w-[700px] border-collapse text-left text-sm">
        <thead className="border-b border-border text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-medium">Titre</th>
            <th className="px-4 py-3 font-medium">Type</th>
            <th className="px-4 py-3 font-medium">Statut</th>
            <th className="px-4 py-3 font-medium">Mis à jour</th>
            <th className="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          {posts.map((post) => (
            <PostRow
              actioningId={actioningId}
              key={post.id}
              onTogglePublish={onTogglePublish}
              post={post}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PostRow({
  actioningId,
  onTogglePublish,
  post,
}: {
  actioningId: string | null;
  onTogglePublish: (post: AdminPost) => void;
  post: AdminPost;
}) {
  const isActioning = actioningId === post.id;
  const publishLabel = post.status === "published" ? "Dépublier" : "Publier";

  return (
    <tr className="border-b border-border last:border-b-0">
      <td className="px-4 py-4">
        <p className="font-semibold text-foreground">{post.title}</p>
        <p className="mt-1 font-mono text-xs text-muted-foreground">{post.slug}</p>
      </td>
      <td className="px-4 py-4">
        <span className="inline-flex min-h-7 items-center border border-border px-2 text-xs font-medium text-foreground">
          {typeLabels[post.type]}
        </span>
      </td>
      <td className="px-4 py-4">
        <span
          className={
            post.status === "published"
              ? "font-medium text-success"
              : "font-medium text-muted-foreground"
          }
        >
          {post.status === "published" ? "Publié" : "Brouillon"}
        </span>
      </td>
      <td className="px-4 py-4 text-muted-foreground">
        <time dateTime={post.updatedAt}>
          {new Intl.DateTimeFormat("fr-FR").format(new Date(post.updatedAt))}
        </time>
      </td>
      <td className="px-4 py-4">
        <div className="flex gap-2">
          <Link
            className="inline-flex min-h-9 items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent"
            href={`/admin/actualites/${post.id}`}
          >
            <Pencil aria-hidden="true" className="size-3" />
            Éditer
          </Link>
          <button
            className="inline-flex min-h-9 items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={isActioning}
            onClick={() => onTogglePublish(post)}
            type="button"
          >
            <FilePenLine aria-hidden="true" className="size-3" />
            {isActioning ? "En cours..." : publishLabel}
          </button>
        </div>
      </td>
    </tr>
  );
}

function EmptyPanel({
  children,
  icon,
  title,
}: Readonly<{
  children: ReactNode;
  icon: ReactNode;
  title: string;
}>) {
  return (
    <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
      {icon}
      <h2 className="font-heading text-2xl font-semibold text-foreground">{title}</h2>
      <p className="max-w-md text-sm leading-6 text-muted-foreground">{children}</p>
    </div>
  );
}

function isPostListPayload(payload: unknown): payload is { data: AdminPost[] } {
  if (!payload || typeof payload !== "object" || !("data" in payload)) return false;
  return Array.isArray((payload as { data: unknown }).data);
}
