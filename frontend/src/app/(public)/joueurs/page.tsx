import { buildPageMetadata } from "@/lib/seo";
import { CommunityDirectory } from "@/features/community/community-directory";

export const metadata = buildPageMetadata({
  title: "Membres",
  description:
    "L'annuaire des joueurs ArchiLAN : top du classement par XP, membres récemment actifs et tes amis.",
  path: "/joueurs",
});

type Props = {
  searchParams: Promise<{ search?: string }>;
};

/**
 * The full members directory (story 30.38). It used to be /communaute itself; that route is now the
 * community hub, which previews members here and hands the search off to this page.
 */
export default async function JoueursPage({ searchParams }: Props) {
  const { search } = await searchParams;

  return (
    <div className="mx-auto w-full max-w-content">
      <CommunityDirectory initialSearch={search ?? ""} />
    </div>
  );
}
