import { cache } from "react";
import { cookies } from "next/headers";
import { env } from "@/lib/env";
import { parseFeedPayload, type FeedEvent } from "./feed-api";

/**
 * Server-side fetch of the session's persisted feed, for the recap page (SSR). Forwards the viewer's
 * cookies so a private personal-run feed loads for its owner/participants (same rule as the recap,
 * story 32.6); an event or published feed needs no auth. Returns [] on any error so the page never
 * breaks over it.
 */
export const getSessionFeed = cache(async (sessionId: string): Promise<FeedEvent[]> => {
  try {
    const cookieHeader = (await cookies()).toString();
    const response = await fetch(`${env.apiBaseUrl}/parties/${sessionId}/feed`, {
      cache: "no-store",
      headers: cookieHeader ? { cookie: cookieHeader } : undefined,
    });

    if (!response.ok) {
      return [];
    }

    return parseFeedPayload(await response.json());
  } catch {
    return [];
  }
});
