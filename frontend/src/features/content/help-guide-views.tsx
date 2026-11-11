import Link from "next/link";
import { ArrowLeft, ArrowRight, BookOpen, Download } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";
import { helpGuidePath, type HelpGuide, type HelpGuideCard } from "./help-guides-api";

/** The Archipelago guide has a page of its own (install steps, client), always first in the index. */
export const ARCHIPELAGO_GUIDE_CARD: HelpGuideCard = {
  slug: "archipelago",
  title: "Installer Archipelago",
  summary: "Le client à installer et les étapes pour préparer ta première partie multiworld.",
};

/** Story 53.5: the index of the help guides, the Archipelago one first. */
export function HelpGuideIndex({ guides }: { guides: HelpGuideCard[] }) {
  return (
    <div className="mx-auto grid w-full max-w-content gap-10">
      <header className="grid gap-4 border-b border-border pb-8">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Aide</p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">Les guides d&apos;ArchiLAN</h1>
        <p className="text-lg leading-8 text-muted-foreground">
          Les pelles, le Passeur, le blind test... Tout ce qui fait une ArchiLAN, expliqué simplement.
        </p>
      </header>
      <ul className="grid gap-4 sm:grid-cols-2">
        {[ARCHIPELAGO_GUIDE_CARD, ...guides].map((guide) => (
          <li key={guide.slug}>
            <HelpGuideCardLink guide={guide} />
          </li>
        ))}
      </ul>
    </div>
  );
}

export function HelpGuideCardLink({ guide }: { guide: HelpGuideCard }) {
  const Icon = guide.slug === ARCHIPELAGO_GUIDE_CARD.slug ? Download : BookOpen;

  return (
    <Link
      className="group grid h-full content-start gap-2 rounded-lg border border-border bg-surface p-5 transition-colors hover:border-accent"
      href={helpGuidePath(guide.slug)}
    >
      <span className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground">
        <Icon aria-hidden="true" className="size-5 text-accent-text" />
        {guide.title}
      </span>
      <span className="text-sm text-muted-foreground">{guide.summary}</span>
      <span className="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-accent-text group-hover:text-accent-text-hover">
        Lire le guide
        <ArrowRight aria-hidden="true" className="size-4" />
      </span>
    </Link>
  );
}

/** Story 53.5: one guide, its text written by the orga in markdown. */
export function HelpGuideArticle({ guide }: { guide: HelpGuide }) {
  return (
    <article className="mx-auto grid w-full max-w-content gap-8">
      <header className="grid gap-4 border-b border-border pb-8">
        <Link className="inline-flex w-fit items-center gap-1 text-sm font-semibold text-accent-text hover:text-accent-text-hover" href="/aide">
          <ArrowLeft aria-hidden="true" className="size-4" />
          Tous les guides
        </Link>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">{guide.title}</h1>
        <p className="text-lg leading-8 text-muted-foreground">{guide.summary}</p>
      </header>
      <Markdown className="text-base leading-7 text-foreground">{guide.body}</Markdown>
    </article>
  );
}
