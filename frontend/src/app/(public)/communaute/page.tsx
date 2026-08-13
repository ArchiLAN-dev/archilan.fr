import { buildPageMetadata } from "@/lib/seo";
import { getPublicEvents } from "@/features/events/public-events-api";
import { fetchCommunityStats, fetchLeaderboard } from "@/features/community/community-api";
import { fetchCommunityOverview } from "@/features/community/community-overview-api";
import { fetchDirectoryServerSide } from "@/features/community/community-directory-api";
import { CommunityHub } from "@/features/community/community-hub";

export const metadata = buildPageMetadata({
  title: "Communauté",
  description:
    "La communauté ArchiLAN : joueurs Archipelago francophones, classements, succès débloqués et membres en jeu.",
  path: "/communaute",
});

export default async function CommunautePage() {
  // Server-side timestamp handed to the leaderboard's TanStack initialData (AC-HK5); never read
  // during a client render.
  // eslint-disable-next-line react-hooks/purity
  const leaderboardFetchedAt = Date.now();

  // Every fetcher resolves to null/empty on failure, so one dead endpoint hides its section instead of
  // failing the page.
  const [overview, stats, leaderboard, eventsData, memberPreview] = await Promise.all([
    fetchCommunityOverview(),
    fetchCommunityStats(),
    fetchLeaderboard("goals", 20),
    getPublicEvents(),
    fetchDirectoryServerSide({ sort: "xp", search: "", friendsOnly: false, page: 1 }),
  ]);

  const events = [...eventsData.upcoming, ...eventsData.past].map((event) => ({
    id: event.id,
    title: event.title,
  }));

  return (
    <div className="mx-auto w-full max-w-content">
      <CommunityHub
        events={events}
        leaderboard={leaderboard}
        leaderboardFetchedAt={leaderboardFetchedAt}
        memberPreview={memberPreview?.rows ?? []}
        overview={overview}
        stats={stats}
      />
    </div>
  );
}
