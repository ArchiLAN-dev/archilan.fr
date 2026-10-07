import type { Metadata } from "next";

import { CollectionPanel } from "@/features/wallet/collection-page";

export const metadata: Metadata = { title: "Collection" };

export default function CollectionPage() {
  return <CollectionPanel />;
}
