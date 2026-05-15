import type { Metadata } from "next";
import { getPublicEvents } from "@/features/events/public-events-api";
import { fetchLeaderboard } from "@/features/community/community-api";
import { LeaderboardClient } from "@/features/community/leaderboard-client";

export const metadata: Metadata = {
  title: "Classements communautaires",
  description: "Découvrez les meilleurs joueurs ArchiLAN par objectifs, checks, et vitesse de complétion.",
  openGraph: {
    title: "Classements communautaires | ArchiLAN",
    description: "Découvrez les meilleurs joueurs ArchiLAN par objectifs, checks, et vitesse de complétion.",
    type: "website",
    locale: "fr_FR",
  },
};

export default async function ClassementsPage() {
  // eslint-disable-next-line react-hooks/purity
  const fetchedAt = Date.now();
  const [initialData, eventsData] = await Promise.all([
    fetchLeaderboard("goals", 20),
    getPublicEvents(),
  ]);

  const events = [...eventsData.upcoming, ...eventsData.past].map((e) => ({
    id: e.id,
    title: e.title,
  }));

  return (
    <div className="mx-auto w-full max-w-3xl grid gap-8">
      <header className="grid gap-2">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          Communauté
        </p>
        <h1 className="font-heading text-3xl font-bold text-foreground md:text-4xl">
          Classements
        </h1>
        <p className="text-muted-foreground">
          Les meilleurs joueurs ArchiLAN, toutes sessions confondues.
        </p>
      </header>

      <LeaderboardClient events={events} initialData={initialData} initialDataFetchedAt={fetchedAt} />
    </div>
  );
}
