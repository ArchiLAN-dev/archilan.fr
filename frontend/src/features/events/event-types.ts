export type EventStatus = "open" | "upcoming" | "full" | "completed";

export type EventAttendanceMode = "offline" | "online" | "mixed";

export type PublicEvent = {
  id: string;
  title: string;
  date: string;
  dateIso?: string;
  endDateIso?: string;
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
