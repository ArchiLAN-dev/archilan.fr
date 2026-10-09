import type { Metadata } from "next";

import { NotificationPreferencesCard } from "@/features/auth/notification-preferences-card";
import { PushNotificationsCard } from "@/features/auth/push-notifications-card";

export const metadata: Metadata = { title: "Notifications" };

export default function NotificationsPage() {
  return (
    <div className="grid gap-6">
      <PushNotificationsCard />
      {/* Story 43.11b: per type, the bell and the devices, the bell only, or nothing. */}
      <NotificationPreferencesCard />
    </div>
  );
}
