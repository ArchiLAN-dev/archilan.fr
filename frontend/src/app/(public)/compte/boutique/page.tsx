import type { Metadata } from "next";

import { ShopPage } from "@/features/wallet/shop-page";

export const metadata: Metadata = { title: "Boutique" };

export default function BoutiquePage() {
  return <ShopPage />;
}
