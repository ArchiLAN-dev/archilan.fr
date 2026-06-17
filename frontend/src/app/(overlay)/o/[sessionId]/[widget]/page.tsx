"use client";

import { notFound, useParams, useSearchParams } from "next/navigation";
import { useEffect, useMemo } from "react";

import { GoalsOverlay } from "@/features/overlay/goals-overlay";
import { LogOverlay } from "@/features/overlay/log-overlay";
import { NotificationsOverlay } from "@/features/overlay/notifications-overlay";
import { ReachableOverlay } from "@/features/overlay/reachable-overlay";
import { parseOverlayParams } from "@/features/overlay/overlay-params";

const WIDGETS = ["notifications", "goals", "log", "reachable"] as const;
type Widget = (typeof WIDGETS)[number];

function isWidget(value: string): value is Widget {
  return (WIDGETS as readonly string[]).includes(value);
}

export default function OverlayWidgetPage() {
  const routeParams = useParams();
  const searchParams = useSearchParams();

  const sessionId = typeof routeParams.sessionId === "string" ? routeParams.sessionId : "";
  const widget = typeof routeParams.widget === "string" ? routeParams.widget : "";

  const params = useMemo(
    () => parseOverlayParams(new URLSearchParams(searchParams.toString())),
    [searchParams],
  );

  // Make the document transparent (or a solid chroma key) so OBS can composite the overlay. Restore on
  // unmount in case the route is reused within the same browser session.
  useEffect(() => {
    const value = params.bg ? `#${params.bg}` : "transparent";
    const prevBody = document.body.style.background;
    const prevHtml = document.documentElement.style.background;
    document.body.style.background = value;
    document.documentElement.style.background = value;
    return () => {
      document.body.style.background = prevBody;
      document.documentElement.style.background = prevHtml;
    };
  }, [params.bg]);

  if (!isWidget(widget)) {
    notFound();
  }

  if (widget === "notifications") {
    return <NotificationsOverlay params={params} sessionId={sessionId} />;
  }
  if (widget === "goals") {
    return <GoalsOverlay params={params} sessionId={sessionId} />;
  }
  if (widget === "reachable") {
    return <ReachableOverlay params={params} sessionId={sessionId} />;
  }
  return <LogOverlay params={params} sessionId={sessionId} />;
}
