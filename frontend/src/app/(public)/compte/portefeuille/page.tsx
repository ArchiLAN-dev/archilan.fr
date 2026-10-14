import type { Metadata } from "next";

import { WalletPanel } from "@/features/wallet/wallet-panel";

// Story 41.34: "Pelles" in the menus; the URL stays, notifications and pages link to it.
export const metadata: Metadata = { title: "Pelles" };

export default function PortefeuillePage() {
  return <WalletPanel />;
}
