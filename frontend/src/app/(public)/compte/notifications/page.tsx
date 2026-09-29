import type { Metadata } from "next";

import { PushNotificationsCard } from "@/features/auth/push-notifications-card";

export const metadata: Metadata = { title: "Notifications" };

export default function NotificationsPage() {
  return <PushNotificationsCard />;
}
