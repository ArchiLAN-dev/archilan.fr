"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useMemo } from "react";

import { parsePartnerScreenParams, PartnerScreen } from "@/features/content/partner-screen";
import { QueryProvider } from "@/lib/query-provider";

/** Story 45.4: the partners for OBS (`?mode` absent) or the LAN screen (`?mode=ecran`), `?event=<id>` to narrow. */
function PartnerScreenRoute() {
  const searchParams = useSearchParams();
  const params = useMemo(() => parsePartnerScreenParams(new URLSearchParams(searchParams.toString())), [searchParams]);
  return <PartnerScreen params={params} />;
}

export default function PartnerScreenPage() {
  return (
    // The overlay group has no shell, hence no query client of its own; useSearchParams needs a Suspense boundary.
    <QueryProvider>
      <Suspense>
        <PartnerScreenRoute />
      </Suspense>
    </QueryProvider>
  );
}
