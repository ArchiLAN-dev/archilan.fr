import type { Metadata } from "next";

import { CommunityFeedPanel } from "@/features/community/community-activity";

export const metadata: Metadata = { title: "Activité" };

export default function ActivitePage() {
  return <CommunityFeedPanel />;
}
