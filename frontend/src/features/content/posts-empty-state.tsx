import Link from "next/link";
import { CalendarDays, Radio } from "lucide-react";
import { externalLinks } from "@/lib/external-links";

export function PostsEmptyState() {
  return (
    <div className="card-glow rounded-lg border border-border p-6 text-center">
      <Radio aria-hidden="true" className="mx-auto mb-4 size-8 text-accent-text" />
      <p className="font-heading text-xl font-semibold text-foreground">
        Aucune actualité publiée pour le moment
      </p>
      <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
        Retrouve les prochains rendez-vous ArchiLAN ou suis la chaîne Twitch pour ne rien manquer.
      </p>
      <div className="mt-5 flex flex-col justify-center gap-3 sm:flex-row">
        <Link
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href="/evenements"
        >
          <CalendarDays aria-hidden="true" className="size-4" />
          Voir les événements
        </Link>
        <a
          className="inline-flex min-h-11 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href={externalLinks.twitch}
          rel="noopener noreferrer"
          target="_blank"
        >
          Ouvrir Twitch
        </a>
      </div>
    </div>
  );
}
