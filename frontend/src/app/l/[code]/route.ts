import { linkRedirect } from "@/features/links/link-redirect";
import { recordLinkVisit } from "@/features/links/links-api";
import { env } from "@/lib/env";

/** Story 55.1: a tracked link. Counts the pass (not robots nor prefetches), then sends to its target. */
export const dynamic = "force-dynamic";

type Context = { params: Promise<{ code: string }> };

export async function GET(request: Request, { params }: Context): Promise<Response> {
  const { code } = await params;
  return linkRedirect(request, code, env.appUrl, recordLinkVisit);
}

/** A HEAD (link checkers, previews) gets the same redirect, never counted. */
export async function HEAD(request: Request, { params }: Context): Promise<Response> {
  const { code } = await params;
  return linkRedirect(request, code, env.appUrl, recordLinkVisit);
}
