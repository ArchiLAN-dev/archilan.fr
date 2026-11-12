"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useMemo } from "react";

import { parseRegieToken } from "@/features/streaming/broadcast-screen";
import { parseWhepTestUrls, WhepTestScreen } from "@/features/streaming/whep-player";

/**
 * Story 47.4 (spike): reads MediaMTX paths over WHEP from an archilan.fr page, `?url=<whep>&url=<whep>`. v0.66.1: with
 * the event's régie token, `&regie=`, which MediaMTX asks for each read.
 */
function WhepTestRoute() {
  const searchParams = useSearchParams();
  const params = useMemo(() => new URLSearchParams(searchParams.toString()), [searchParams]);
  const urls = useMemo(() => parseWhepTestUrls(params), [params]);
  return <WhepTestScreen token={parseRegieToken(params)} urls={urls} />;
}

export default function WhepTestPage() {
  return (
    <Suspense>
      <WhepTestRoute />
    </Suspense>
  );
}
