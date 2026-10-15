/** Story 4.23: where registrations stand, computed by `eventStatusAt`. */
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
  stats?: {
    players: number;
    games: number;
    duration: string;
  };
};
