import { env } from "./env";

type UnauthenticatedHandler = (nextPath: string) => void;

let _onUnauthenticated: UnauthenticatedHandler | null = null;

export function registerUnauthenticatedHandler(fn: UnauthenticatedHandler): void {
  _onUnauthenticated = fn;
}

let isRefreshing = false;
const refreshQueue: Array<(ok: boolean) => void> = [];

async function attemptRefresh(): Promise<boolean> {
  if (isRefreshing) {
    return new Promise<boolean>((resolve) => {
      refreshQueue.push(resolve);
    });
  }

  isRefreshing = true;
  try {
    const res = await fetch(`${env.apiBaseUrl}/auth/refresh`, {
      method: "POST",
      credentials: "include",
    });
    const ok = res.status === 204;
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
