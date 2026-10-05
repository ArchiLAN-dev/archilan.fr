import { redirect } from "next/navigation";

/** Story 41.15: the weekly quests have two pages, the weeks first. */
export default function AdminQuetesPage() {
  redirect("/admin/quetes/semaines");
}
