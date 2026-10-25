import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { BlindTestAnimationBridge } from "@/features/wallet/blind-test-animation-bridge";

export const metadata: Metadata = {
  title: "Animation du blind test",
  robots: { index: false, follow: false },
};

type Props = {
  searchParams: Promise<{ state?: string | string[] }>;
};

/** Story 41.53: where the blind test app sends an admin to open its console, régie or room screen. */
export default async function BlindTestAnimationPage({ searchParams }: Props) {
  const { state } = await searchParams;
  if (typeof state !== "string" || "" === state) {
    notFound();
  }

  return (
    <div className="mx-auto max-w-content px-4 py-16 sm:px-6">
      <BlindTestAnimationBridge state={state} />
    </div>
  );
}
