import Link from "next/link";
import Image from "next/image";
import { CalendarDays, Clock, FileText, ImageIcon } from "lucide-react";
import type { PublicPost } from "./content-types";
import { getPostTypeLabel } from "./mock-posts";

export function PostCard({ post }: { post: PublicPost }) {
  return (
    <article className="card-glow grid overflow-hidden rounded-lg border border-border">
      <PostCover post={post} />

      <div className="grid p-5">
      <div className="flex items-center justify-between gap-3">
        <span className="rounded border border-accent/50 px-2 py-1 text-xs font-semibold text-accent-text">
          {getPostTypeLabel(post.type)}
        </span>
        <span className="inline-flex items-center gap-2 text-xs text-muted-foreground">
          <Clock aria-hidden="true" className="size-3.5 text-accent-text" />
          {post.readingTime}
        </span>
      </div>

      <h2 className="mt-4 font-heading text-2xl font-semibold leading-tight text-foreground">
        {post.title}
      </h2>

      <p className="mt-3 text-sm leading-6 text-muted-foreground">{post.excerpt}</p>

      <div className="mt-auto flex flex-col gap-4 pt-6">
        <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
          <CalendarDays aria-hidden="true" className="size-4 text-accent-text" />
          <time dateTime={post.publishedAtIso}>{post.publishedAt}</time>
        </span>
        <Link
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href={`/actualites/${post.slug}`}
        >
          Lire l&apos;article
          <FileText aria-hidden="true" className="size-4" />
        </Link>
      </div>
      </div>
    </article>
  );
}

function PostCover({ post }: { post: PublicPost }) {
  return (
    <div className="relative aspect-[16/9] overflow-hidden border-b border-border bg-surface">
      {post.coverImageUrl ? (
        <Image
          alt=""
          aria-hidden="true"
          className="object-cover"
          fill
          sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw"
          src={post.coverImageUrl}
          unoptimized={post.coverImageUrl.startsWith("http://") || post.coverImageUrl.startsWith("https://")}
        />
      ) : (
        <div className="flex h-full items-center justify-center bg-[linear-gradient(135deg,color-mix(in_oklab,var(--color-surface)_88%,var(--color-accent)),var(--color-background))]">
          <ImageIcon aria-hidden="true" className="size-10 text-muted-foreground/45" />
        </div>
      )}
      <div className="absolute inset-0 bg-gradient-to-t from-background/65 via-transparent to-transparent" />
    </div>
  );
}
