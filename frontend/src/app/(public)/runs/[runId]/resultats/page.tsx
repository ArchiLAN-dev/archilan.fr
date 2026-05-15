import type { Metadata } from "next";
import { env } from "@/lib/env";
import { getRunResults } from "@/features/runs/run-results-api";
import { RunResultsNotFound, RunResultsPage } from "@/features/runs/run-results-page";

type Props = {
  params: Promise<{ runId: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { runId } = await params;
  const results = await getRunResults(runId);

  if (!results) {
    return {
      title: "Résultats non disponibles",
      robots: { index: false, follow: false },
    };
  }

  const title = `Résultats de la run ${results.eventName}`;

  return {
    title,
    metadataBase: new URL(env.appUrl),
    openGraph: {
      title: `${title} | ArchiLAN`,
      siteName: "ArchiLAN",
      type: "website",
      locale: "fr_FR",
    },
    twitter: {
      card: "summary",
      title: `${title} | ArchiLAN`,
    },
  };
}

export default async function RunResultatsPage({ params }: Props) {
  const { runId } = await params;
  const results = await getRunResults(runId);

  if (!results) {
    return <RunResultsNotFound runId={runId} />;
  }

  return <RunResultsPage results={results} runId={runId} />;
}
