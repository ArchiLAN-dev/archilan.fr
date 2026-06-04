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
  displayName: string | null;
  joinedAt: string;
  slotCount: number;
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
  inviteToken: string;
  gameSelectionConfig: PersonalRunGame[] | null;
  connectionHost: string | null;
  connectionPort: number | null;
  connectionPassword: string | null;
  isOwner: boolean;
  participants: PersonalRunParticipant[];
  sessionId: string | null;
  lastActivityAt: string | null;
  pausedWithoutSave: boolean;
  validationErrors: ValidationSlotError[] | null;
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
