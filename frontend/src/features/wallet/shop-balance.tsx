"use client";

import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { PelleAmount } from "./pelle-amount";
import { fetchMyWallet } from "./wallet-api";

/**
 * Story 41.12: the gold balance beside « Boutique » in the site header, for a signed-in member - under the wallet
 * page's query key, so a purchase or a credit shows here at once.
 */
export function ShopBalance() {
  const { user, loading } = useAuth();
  const signedIn = user !== null && !loading;
  const { data: wallet } = useQuery({ queryKey: ["my-wallet", 1], queryFn: () => fetchMyWallet(1), staleTime: DEFAULT_STALE_TIME, retry: false, enabled: signedIn });

  if (!signedIn || !wallet) return null;
  return <PelleAmount amount={wallet.gold} className="ml-1.5 rounded-full bg-warning/15 px-2 py-0.5 text-xs font-semibold text-warning" />;
}
