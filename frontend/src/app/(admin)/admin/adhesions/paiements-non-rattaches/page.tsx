import type { Metadata } from "next";
import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { AdminUnmatchedHelloAssoOrders } from "@/features/admin/admin-unmatched-helloasso-orders";

export const metadata: Metadata = {
  title: "Paiements non rattachés",
};

export default function AdminUnmatchedHelloAssoPage() {
  return (
    <div className="w-full px-4 py-6 md:py-8">
      <div className="mb-6">
        <Link
          href="/admin/adhesions"
          className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ChevronLeft className="size-4" />
          Adhésions
        </Link>
        <h1 className="text-xl font-semibold text-foreground">
          Paiements HelloAsso non rattachés
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Paiements d&apos;adhésion reçus dont l&apos;email HelloAsso ne correspond à
          aucun compte archilan.fr. Rattache-les manuellement au bon compte.
        </p>
      </div>

      <AdminUnmatchedHelloAssoOrders />
    </div>
  );
}
