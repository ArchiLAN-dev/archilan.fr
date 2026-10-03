import type { Metadata } from "next";

import { WalletPanel } from "@/features/wallet/wallet-panel";

export const metadata: Metadata = { title: "Portefeuille" };

export default function PortefeuillePage() {
  return <WalletPanel />;
}
