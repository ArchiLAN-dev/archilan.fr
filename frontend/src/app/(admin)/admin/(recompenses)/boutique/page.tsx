import type { Metadata } from "next";

import { AdminShopPage } from "@/features/wallet/admin-shop-page";

export const metadata: Metadata = {
  title: "Boutique",
};

export default function AdminBoutiquePage() {
  return <AdminShopPage />;
}
