import { env } from "./env";

type UnauthenticatedHandler = (nextPath: string) => void;

let _onUnauthenticated: UnauthenticatedHandler | null = null;

export function registerUnauthenticatedHandler(fn: UnauthenticatedHandler): void {
  _onUnauthenticated = fn;
}

// In-tab deduplication: requests in the same tab queue behind the first refresh.
let isRefreshing = false;
const refreshQueue: Array<(ok: boolean) => void> = [];

// Cross-tab coordination via Web Locks API.
// When tab B acquires the lock after tab A already refreshed, it checks this
// localStorage key to skip the redundant refresh request.
const REFRESH_TS_KEY = "archilan_refresh_ts";
const REFRESH_RECENT_MS = 5_000;

async function doRefreshUnderLock(): Promise<boolean> {
  // Another tab may have just refreshed — skip if so.
  const last = Number(localStorage.getItem(REFRESH_TS_KEY) ?? 0);
  if (Date.now() - last < REFRESH_RECENT_MS) {
    return true;
  }

  const res = await fetch(`${env.apiBaseUrl}/auth/refresh`, {
    method: "POST",
    credentials: "include",
  });
  const ok = res.status === 204;
  if (ok) {
    localStorage.setItem(REFRESH_TS_KEY, String(Date.now()));
  }
  return ok;
}

async function attemptRefresh(): Promise<boolean> {
  if (isRefreshing) {
    return new Promise<boolean>((resolve) => {
      refreshQueue.push(resolve);
    });
  }

  isRefreshing = true;
  try {
    let ok: boolean;
    if (typeof navigator !== "undefined" && navigator.locks) {
      ok = await navigator.locks.request("archilan_token_refresh", doRefreshUnderLock);
    } else {
      ok = await doRefreshUnderLock();
    }
    refreshQueue.splice(0).forEach((cb) => cb(ok));
    return ok;
  } catch {
    refreshQueue.splice(0).forEach((cb) => cb(false));
    return false;
  } finally {
    isRefreshing = false;
  }
}

const BYPASS_PATHS = ["/auth/refresh", "/auth/login"];

export async function apiFetch(
  input: RequestInfo | URL,
  init?: RequestInit,
): Promise<Response> {
  const url =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.href
        : (input as Request).url;

  const opts: RequestInit = { credentials: "include", ...init };
  const response = await fetch(input, opts);

  if (response.status !== 401 || BYPASS_PATHS.some((p) => url.includes(p))) {
    return response;
  }

  const refreshOk = await attemptRefresh();

  if (!refreshOk) {
    const nextPath =
      typeof window !== "undefined" ? window.location.pathname : "/";
    _onUnauthenticated?.(nextPath);
    return response;
  }

  return fetch(input, opts);
}
