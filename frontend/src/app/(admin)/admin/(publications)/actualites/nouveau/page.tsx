import type { Metadata } from "next";

import { AdminPostForm } from "@/features/admin/admin-post-form";

export const metadata: Metadata = {
  title: "Nouveau post",
};

export default function AdminNewPostPage() {
  return <AdminPostForm mode="create" />;
}
