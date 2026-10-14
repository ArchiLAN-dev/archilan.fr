import type { Metadata } from "next";

import { WalletPanel } from "@/features/wallet/wallet-panel";

// Story 41.34: "Pelles", at /compte/pelles; /compte/portefeuille redirects here (next.config).
export const metadata: Metadata = { title: "Pelles" };

export default function PellesPage() {
  return <WalletPanel />;
}
