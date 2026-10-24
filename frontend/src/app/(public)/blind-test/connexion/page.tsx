import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { BlindTestLoginBridge } from "@/features/wallet/blind-test-login-bridge";

export const metadata: Metadata = {
  title: "Connexion au blind test",
  robots: { index: false, follow: false },
};

type Props = {
  searchParams: Promise<{ event?: string | string[]; state?: string | string[] }>;
};

/** Story 41.45: where the blind test app sends a member to learn who they are. */
export default async function BlindTestLoginPage({ searchParams }: Props) {
  const { event, state } = await searchParams;
  if (typeof event !== "string" || typeof state !== "string" || "" === event || "" === state) {
    notFound();
  }

  return (
    <div className="mx-auto max-w-content px-4 py-16 sm:px-6">
      <BlindTestLoginBridge eventId={event} state={state} />
    </div>
  );
}
