/** Story 4.23: where registrations stand, computed by `eventStatusAt`. */
import type { EventPartnerRef } from "@/features/content/partners-api";

export type EventStatus = "upcoming" | "open" | "full" | "closed" | "in-progress" | "completed";

export type EventAttendanceMode = "offline" | "online" | "mixed";

export type PublicEvent = {
  id: string;
  title: string;
  date: string;
  dateIso?: string;
  endDateIso?: string;
  /** Story 4.23: the registration window, shown before it opens. */
  registrationOpensAtIso?: string;
  registrationClosesAtIso?: string;
  location: string;
  description: string;
  coverImageUrl?: string | null;
  photoGallery?: string[];
  attendanceMode?: EventAttendanceMode;
  status: EventStatus;
  /** Story 4.21: private event, open to active adherents or to a member with its access code. */
  membersOnly?: boolean;
  capacity?: {
    remaining: number;
    total: number;
  };
  recapAvailable?: boolean;
  recap?: string;
  vodUrl?: string;
  checkoutEmbedUrl?: string;
  checkoutUnavailable?: boolean;
  /** Story 45.3: the partners thanked on the event, by reference (joined to `getPartners()` for display). */
  partners?: EventPartnerRef[];
  stats?: {
    players: number;
    games: number;
    duration: string;
  };
};
