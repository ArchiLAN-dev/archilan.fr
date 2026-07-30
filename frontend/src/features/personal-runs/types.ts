export type PersonalRunStatus =
  | "draft"
  | "starting"
  | "active"
  | "stopping"
  | "idle"
  | "restarting"
  | "completed"
  | "cancelled";

export type PersonalRunGame = {
  gameId: string;
};

export type PersonalRunParticipant = {
  userId: string;
  slug: string | null;
  displayName: string | null;
  avatarUrl: string | null;
  joinedAt: string;
  slotCount: number;
  // Status badges, coherent with the player profile (story 30.37): live membership, admin, level,
  // and live presence. `isMember` is the live-membership badge, never the stale ROLE_MEMBER.
  isMember: boolean;
  isAdmin: boolean;
  level: number;
  playing: boolean;
};

export type ParticipantLevel = {
  level: number;
  xp: number;
  xpIntoLevel: number;
  xpForNextLevel: number;
};

export type ParticipantStats = {
  runsParticipated: number;
  goalCompletions: number;
  totalChecksDone: number;
  achievementsUnlocked: number;
};

export type ParticipantIdentity = {
  userId: string;
  slug: string | null;
  displayName: string | null;
  avatarUrl: string | null;
  isAdmin: boolean;
  level: ParticipantLevel;
  stats: ParticipantStats;
};

export type ParticipantGameSlot = {
  slotId: string;
  gameId: string;
  slotOrder: number;
  gameName: string;
  gameSlug: string | null;
  description: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
  availability: string | null;
  platforms: string[];
  isApworldReady: boolean;
  playerYaml: string | null;
};

export type ValidationSlotError = {
  slotName: string;
  errors: string[];
};

export type PersonalRun = {
  id: string;
  ownerId: string;
  title: string;
  status: PersonalRunStatus;
  inviteToken: string | null;
  gameSelectionConfig: PersonalRunGame[] | null;
  connectionHost: string | null;
  connectionPort: number | null;
  connectionPassword: string | null;
  isOwner: boolean;
  participants: PersonalRunParticipant[];
  sessionId: string | null;
  // Whether the finished run's recap is publicly shareable (story 32.5). Falsy on an older API payload.
  recapPublic: boolean;
  lastActivityAt: string | null;
  pausedWithoutSave: boolean;
  validationErrors: ValidationSlotError[] | null;
  // Bounded stderr excerpt of a failed generation, owner-only (story 9.40). Absent on older API payloads.
  generationLogExcerpt?: string | null;
  adminPassword: string | null;
  createdAt: string;
  updatedAt: string;
};

export type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  availability: string;
};
