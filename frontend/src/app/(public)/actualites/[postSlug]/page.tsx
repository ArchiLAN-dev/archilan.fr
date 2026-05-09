import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CalendarDays, Clock, ExternalLink } from "lucide-react";
import { env } from "@/lib/env";
import type { PublicPost, PublicPostType } from "@/features/content/content-types";
import { getPostTypeLabel } from "@/features/content/mock-posts";
import { getPublicPostBySlugFromApi } from "@/features/content/public-posts-api";

type PostPageProps = {
  params: Promise<{ postSlug: string }>;
};

const schemaTypeByPostType: Record<PublicPostType, "NewsArticle" | "Article"> = {
  news: "NewsArticle",
  announcement: "NewsArticle",
  recap: "Article",
};

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: PostPageProps): Promise<Metadata> {
  const { postSlug } = await params;
  const post = await getPublicPostBySlugFromApi(postSlug);

  if (!post) {
    return {
      title: "Article introuvable",
      robots: { index: false, follow: false },
    };
  }

  const canonicalPath = `/actualites/${post.slug}`;

  return {
    title: post.title,
    description: post.excerpt,
    metadataBase: new URL(env.appUrl),
    alternates: {
      canonical: canonicalPath,
    },
    openGraph: {
      title: `${post.title} | ArchiLAN`,
      description: post.excerpt,
      url: canonicalPath,
      siteName: "ArchiLAN",
      type: "article",
      locale: "fr_FR",
      publishedTime: post.publishedAtIso,
      ...(post.coverImageUrl ? { images: [{ url: post.coverImageUrl, alt: post.title }] } : {}),
    },
    twitter: {
      card: "summary",
      title: `${post.title} | ArchiLAN`,
      description: post.excerpt,
    },
  };
}

export default async function PostPage({ params }: PostPageProps) {
  const { postSlug } = await params;
  const post = await getPublicPostBySlugFromApi(postSlug);

  if (!post) {
    notFound();
  }

  const canonicalUrl = new URL(`/actualites/${post.slug}`, env.appUrl).toString();
  const structuredData = getPostStructuredData(post, canonicalUrl);

  return (
    <>
      <script
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(structuredData)
            .replace(/</g, "\\u003c")
            .replace(/>/g, "\\u003e")
            .replace(/&/g, "\\u0026"),
        }}
        type="application/ld+json"
      />

      <article className="mx-auto grid max-w-3xl gap-8">
        {post.coverImageUrl ? <PostHeroImage post={post} /> : null}

        <header className="border-b border-border pb-8">
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
            {getPostTypeLabel(post.type)}
          </p>
          <h1 className="mt-3 font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
            {post.title}
          </h1>
          <p className="mt-5 text-lg leading-8 text-muted-foreground">{post.excerpt}</p>
          <div className="mt-6 flex flex-wrap gap-x-5 gap-y-3 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-2">
              <CalendarDays aria-hidden="true" className="size-4 text-accent-text" />
              <time dateTime={post.publishedAtIso}>{post.publishedAt}</time>
            </span>
            <span className="inline-flex items-center gap-2">
              <Clock aria-hidden="true" className="size-4 text-accent-text" />
              {post.readingTime}
            </span>
          </div>
        </header>

        <div className="grid gap-6 text-base leading-8 text-muted-foreground">
          {post.body.map((paragraph, index) => (
            <p key={`para-${index}`}>{paragraph}</p>
          ))}
        </div>

        <footer className="mt-10 flex flex-col gap-3 border-t border-border pt-6 sm:flex-row">
          {post.relatedEventSlug ? (
            <Link
              className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
              href={`/evenements/${post.relatedEventSlug}`}
            >
              Voir l&apos;événement lié
            </Link>
          ) : null}
          {post.vodUrl ? (
            <a
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              href={post.vodUrl}
              rel="noopener noreferrer"
              target="_blank"
            >
              Voir la VOD
              <ExternalLink aria-hidden="true" className="size-4" />
            </a>
          ) : null}
        </footer>
      </article>
    </>
  );
}

function getPostStructuredData(post: PublicPost, canonicalUrl: string) {
  return {
    "@context": "https://schema.org",
    "@type": schemaTypeByPostType[post.type],
    headline: post.title,
    description: post.excerpt,
    url: canonicalUrl,
    datePublished: post.publishedAtIso,
    author: {
      "@type": "Organization",
      name: "ArchiLAN",
      url: env.appUrl,
    },
    ...(post.coverImageUrl ? { image: post.coverImageUrl } : {}),
  };
}

function PostHeroImage({ post }: { post: PublicPost }) {
  return (
    <section aria-label="Image de couverture de l'article" className="relative -mx-6 overflow-hidden md:-mx-12 lg:-mx-20">
      <div className="relative aspect-[21/9] min-h-56 bg-surface">
        <Image
          alt=""
          aria-hidden="true"
          className="object-cover"
          fill
          priority
          sizes="100vw"
          src={post.coverImageUrl ?? ""}
          unoptimized={post.coverImageUrl?.startsWith("http://") || post.coverImageUrl?.startsWith("https://")}
        />
        <div className="absolute inset-0 bg-gradient-to-t from-background/75 via-background/10 to-transparent" />
      </div>
    </section>
  );
}
