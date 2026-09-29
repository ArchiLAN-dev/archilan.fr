import { redirect } from "next/navigation";

import { moderationPathFor } from "@/features/admin/moderation-filters";

type Props = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

/** Story 39.13: the queues have their own pages; old links land on the right one, filters kept. */
export default async function AdminModerationPage({ searchParams }: Props) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(await searchParams)) {
    if (typeof value === "string") params.set(key, value);
  }

  redirect(moderationPathFor(params));
}
