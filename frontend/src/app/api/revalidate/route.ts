import { revalidateTag } from "next/cache";

import { serverEnv } from "@/lib/env";
import { bearerMatches, readTags } from "@/lib/revalidate-request";

/**
 * Story 34.10: the API drops the cached public pages that show an event or a post as soon as it is written.
 *
 * `{ expire: 0 }` rather than the `max` profile the docs recommend: with `max` the next visitor would still get
 * the stale page (a 404 cached before the event was published, say) while it rebuilds. Here the next request
 * waits for fresh data, which is the whole point. Only POST is exported, so Next.js answers 405 to the rest.
 */
export async function POST(request: Request): Promise<Response> {
  const secret = serverEnv.revalidateSecret();
  if (secret === "") {
    return Response.json({ error: "revalidation_disabled" }, { status: 503 });
  }
  if (!bearerMatches(request.headers.get("authorization"), secret)) {
    return Response.json({ error: "unauthorized" }, { status: 401 });
  }

  const body: unknown = await request.json().catch(() => null);
  const tags = readTags(body);
  if (tags === null) {
    return Response.json({ error: "invalid_tags" }, { status: 400 });
  }

  for (const tag of tags) {
    revalidateTag(tag, { expire: 0 });
  }

  return Response.json({ revalidated: tags });
}
