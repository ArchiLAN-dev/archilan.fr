export type PublicPostType = "news" | "recap" | "announcement";
export type ContentPublicationStatus = "draft" | "published";

export type PublicPost = {
  slug: string;
  title: string;
  type: PublicPostType;
  status: "published";
  excerpt: string;
  coverImageUrl?: string | null;
  publishedAt: string;
  publishedAtIso: string;
  readingTime: string;
  body: string[];
};

export type AdminContentPost = Omit<PublicPost, "status" | "publishedAt" | "publishedAtIso"> & {
  status: ContentPublicationStatus;
  publishedAt?: string;
  publishedAtIso?: string;
  updatedAtIso: string;
};

export type ContentDraftInput = {
  slug: string;
  title: string;
  type: PublicPostType;
  excerpt: string;
  body: string[];
  readingTime: string;
  coverImageUrl?: string | null;
};
