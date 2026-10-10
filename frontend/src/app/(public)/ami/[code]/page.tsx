import type { Metadata } from "next";

import { RequireAuth } from "@/features/auth/require-auth";
import { FriendLinkPage } from "@/features/community/friend-link";

// Story 43.3: a personal link, nothing to index. Not in the sitemap either.
export const metadata: Metadata = {
  title: "Ajouter un ami",
  description: "Ajoute un membre ArchiLAN en ami depuis son lien personnel.",
  robots: { index: false, follow: false },
};

export default async function FriendLinkRoute({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  return (
    <RequireAuth>
      <FriendLinkPage code={code} />
    </RequireAuth>
  );
}
