import { buildPageMetadata } from "@/lib/seo";
import { CommunityDirectory } from "@/features/community/community-directory";

export const metadata = buildPageMetadata({
  title: "Communauté",
  description: "Parcoure les joueurs ArchiLAN : top du classement, membres récemment actifs et tes amis.",
  path: "/communaute",
});

export default function CommunautePage() {
  return (
    <div className="mx-auto w-full max-w-content">
      <CommunityDirectory />
    </div>
  );
}
