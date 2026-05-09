import type { Metadata } from "next";
import { PostCard } from "@/features/content/post-card";
import { PostsEmptyState } from "@/features/content/posts-empty-state";
import { getPublicPosts } from "@/features/content/public-posts-api";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Actualités",
  description: "Actualités, annonces et récaps publics de la communauté ArchiLAN.",
};

export default async function NewsPage() {
  const posts = await getPublicPosts();

  return (
    <div className="mx-auto w-full max-w-7xl grid gap-12">
      <section>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Actualités ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight md:text-5xl">
          Suivre la communauté entre deux sessions.
        </h1>
        <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
          Annonces, récaps d&apos;événements et nouvelles de l&apos;association pour garder le fil
          de l&apos;activité Archipelago.
        </p>
      </section>

      {posts.length > 0 ? (
        <section aria-label="Articles publiés" className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
          {posts.map((post) => (
            <PostCard key={post.slug} post={post} />
          ))}
        </section>
      ) : (
        <PostsEmptyState />
      )}
    </div>
  );
}
