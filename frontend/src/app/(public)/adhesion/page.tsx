import { redirect } from "next/navigation";

import { MEMBERSHIP_HREF } from "@/features/payments/support-links";

/** Story 41.13: joining lives in the « Soutenir ArchiLAN » tab of the shop. */
export default function AdhesionPage() {
  redirect(MEMBERSHIP_HREF);
}
