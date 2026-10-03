import { redirect } from "next/navigation";

/** Story 42.1: the pelles circulation became a section of the statistics page. */
export default function AdminPellesPage() {
  redirect("/admin/statistiques#pelles");
}
