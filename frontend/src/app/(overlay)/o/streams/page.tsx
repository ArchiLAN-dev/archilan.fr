"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useMemo } from "react";

import { BroadcastScreen, parseBroadcastScreenParams } from "@/features/streaming/broadcast-screen";
import { QueryProvider } from "@/lib/query-provider";

/** Story 47.2: the players live on an event for the régie's OBS source, `?event=<id>`, `?mode=rotation`, `?max`, `?duree`. */
function BroadcastScreenRoute() {
  const searchParams = useSearchParams();
  const params = useMemo(() => parseBroadcastScreenParams(new URLSearchParams(searchParams.toString())), [searchParams]);
  return <BroadcastScreen params={params} />;
}

export default function BroadcastScreenPage() {
  return (
    // The overlay group has no shell, hence no query client of its own; useSearchParams needs a Suspense boundary.
    <QueryProvider>
      <Suspense>
        <BroadcastScreenRoute />
      </Suspense>
    </QueryProvider>
  );
}
