/**
 * Story 55.1: what `/l/{code}` does with a request. The pass counts unless a robot or a prefetch made it; the visitor
 * always ends somewhere (home when the link is unknown or the API is down), and only to a target the API gave.
 */

/** Link previews (Discord, X, WhatsApp...) and search engines load a link without anyone clicking it. */
const ROBOT = /bot|crawler|spider|preview|facebookexternalhit|whatsapp|embedly|slurp|headless|lighthouse/i;

export function isRobot(userAgent: string | null): boolean {
  return userAgent === null || userAgent.trim() === "" || ROBOT.test(userAgent);
}

/** A browser prefetching the link (`Purpose: prefetch`, `Sec-Purpose: prefetch;prerender`) is not a visit yet. */
export function isPrefetch(headers: Headers): boolean {
  return [headers.get("purpose"), headers.get("sec-purpose"), headers.get("x-purpose")].some((value) => value !== null && value.toLowerCase().includes("prefetch"));
}

export function shouldCount(method: string, headers: Headers): boolean {
  return method.toUpperCase() === "GET" && !isRobot(headers.get("user-agent")) && !isPrefetch(headers);
}

/** The host of the page the visitor came from; empty when there is none or it cannot be read. The API cleans it again. */
export function referrerHost(referer: string | null): string {
  if (referer === null || referer === "") return "";
  try {
    return new URL(referer).hostname;
  } catch {
    return "";
  }
}

/**
 * Where to send the visitor: a path is on the site, an https address elsewhere. Anything else (the API never stores
 * it, but the redirect does not trust it blindly) goes home.
 */
export function redirectUrl(target: string | null, appUrl: string): string {
  const home = new URL("/", appUrl).toString();
  if (target === null) return home;
  if (target.startsWith("/") && !target.startsWith("//") && !target.includes("\\")) return new URL(target, appUrl).toString();
  if (target.startsWith("https://")) {
    try {
      return new URL(target).toString();
    } catch {
      return home;
    }
  }
  return home;
}

type RecordVisit = (code: string, referrerHost: string, count: boolean) => Promise<string | null>;

export async function linkRedirect(request: Request, code: string, appUrl: string, recordVisit: RecordVisit): Promise<Response> {
  const target = await recordVisit(code, referrerHost(request.headers.get("referer")), shouldCount(request.method, request.headers));
  return new Response(null, {
    status: 302,
    headers: {
      Location: redirectUrl(target, appUrl),
      "Cache-Control": "no-store",
      // The code of a link is not a page to index.
      "X-Robots-Tag": "noindex",
    },
  });
}
