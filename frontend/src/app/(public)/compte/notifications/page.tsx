import type { Metadata } from "next";

import { NotificationPreferencesCard } from "@/features/auth/notification-preferences-card";
import { PushNotificationsCard } from "@/features/auth/push-notifications-card";
import { FollowedGamesCard } from "@/features/games/followed-games-card";

export const metadata: Metadata = { title: "Notifications" };

export default function NotificationsPage() {
  return (
    <div className="grid gap-6">
      <PushNotificationsCard />
      {/* Story 43.11b: per type, the bell and the devices, the bell only, or nothing. */}
      <NotificationPreferencesCard />
      {/* Story 38.23: the games whose new apworlds the member is told of. */}
      <FollowedGamesCard />
    </div>
  );
}
