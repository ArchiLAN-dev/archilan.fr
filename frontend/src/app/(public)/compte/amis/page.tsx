import type { Metadata } from "next";

import { CommunityFriendsPanel } from "@/features/community/community-friends-panel";

export const metadata: Metadata = { title: "Amis" };

export default function AmisPage() {
  return <CommunityFriendsPanel />;
}
