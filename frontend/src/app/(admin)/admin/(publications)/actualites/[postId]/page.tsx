import type { Metadata } from "next";

import { AdminPostForm } from "@/features/admin/admin-post-form";

export const metadata: Metadata = {
  title: "Éditer le post",
};

export default async function AdminEditPostPage({
  params,
}: {
  params: Promise<{ postId: string }>;
}) {
  const { postId } = await params;
  return <AdminPostForm mode="edit" postId={postId} />;
}
