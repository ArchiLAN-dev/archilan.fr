import { redirect } from "next/navigation";

import { SUPPORT_TAB_HREF } from "@/features/payments/support-archilan";

/** Story 41.13: joining lives in the « Soutenir ArchiLAN » tab of the shop. */
export default function AdhesionPage() {
  redirect(`${SUPPORT_TAB_HREF}#adhesion`);
}
