import type { Metadata } from "next";

import { HubQrSheetPage } from "@/features/registrant-hub/hub-qr-sheet";

export const metadata: Metadata = {
  title: "QR code de l'espace",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

/** Story 53.7: the printable QR code of the registrant space. */
export default function EventHubQrPage({ params }: Props) {
  return <HubQrSheetPage params={params} />;
}
