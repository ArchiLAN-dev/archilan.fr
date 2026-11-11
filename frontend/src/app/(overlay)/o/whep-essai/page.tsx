"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useMemo } from "react";

import { parseWhepTestUrls, WhepTestScreen } from "@/features/streaming/whep-player";

/** Story 47.4 (spike): reads MediaMTX paths over WHEP from an archilan.fr page, `?url=<whep>&url=<whep>`. */
function WhepTestRoute() {
  const searchParams = useSearchParams();
  const urls = useMemo(() => parseWhepTestUrls(new URLSearchParams(searchParams.toString())), [searchParams]);
  return <WhepTestScreen urls={urls} />;
}

export default function WhepTestPage() {
  return (
    <Suspense>
      <WhepTestRoute />
    </Suspense>
  );
}
