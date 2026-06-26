import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { ParticipantStreams } from "@/features/streaming/participant-streams";
import type { ParticipantStreamKind } from "@/features/streaming/participant-streams-api";

const KIND_LABELS: Record<ParticipantStreamKind, string> = {
  event: "l'événement",
  run: "la partie",
  weekly: "la run hebdomadaire",
};

function isKind(value: string): value is ParticipantStreamKind {
  return value === "event" || value === "run" || value === "weekly";
}

type Props = {
  params: Promise<{ kind: string; id: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { kind } = await params;
  const title = "Streams des participants";

  return {
    title,
    description: "Toutes les chaînes Twitch des participants, en direct et hors-ligne.",
    openGraph: { title: `${title} | ArchiLAN` },
    ...(isKind(kind) ? {} : { robots: { index: false, follow: false } }),
  };
}

export default async function ParticipantStreamsPage({ params }: Props) {
  const { kind, id } = await params;

  if (!isKind(kind)) {
    notFound();
  }

  return (
    <div className="mx-auto grid w-full max-w-2xl gap-6">
      <nav className="text-sm text-muted-foreground">
        <Link className="inline-flex items-center gap-1 hover:text-foreground" href="/">
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour
        </Link>
      </nav>

      <header>
        <h1 className="font-heading text-3xl font-bold text-foreground">Streams des participants</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Toutes les chaînes Twitch liées à {KIND_LABELS[kind]}.
        </p>
      </header>

      <ParticipantStreams emptyState="message" id={id} kind={kind} showAll />
    </div>
  );
}
