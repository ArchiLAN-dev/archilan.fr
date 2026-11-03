"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useMemo } from "react";

import { MinimapperzScreen, parseMinimapperzScreenParams } from "@/features/wallet/minimapperz-screen";
import { QueryProvider } from "@/lib/query-provider";

/** Story 49.4: the Minimapperz ranking of an event for OBS (`?mode` absent) or the LAN screen (`?mode=ecran`). */
function MinimapperzScreenRoute() {
  const searchParams = useSearchParams();
  const params = useMemo(() => parseMinimapperzScreenParams(new URLSearchParams(searchParams.toString())), [searchParams]);
  return <MinimapperzScreen params={params} />;
}

export default function MinimapperzScreenPage() {
  return (
    // The overlay group has no shell, hence no query client of its own; useSearchParams needs a Suspense boundary.
    <QueryProvider>
      <Suspense>
        <MinimapperzScreenRoute />
      </Suspense>
    </QueryProvider>
  );
}
