import { redirect } from "next/navigation";

/** Story 41.12: the shop moved to /boutique, its cosmetics tab first. */
export default function BoutiquePage() {
  redirect("/boutique");
}
