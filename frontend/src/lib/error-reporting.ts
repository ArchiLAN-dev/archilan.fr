import type { ErrorEvent, EventHint } from "@sentry/nextjs";

/**
 * Story 33.32: the options every Sentry SDK of the front starts with (browser, Node.js server, edge). Errors only,
 * no performance tracing nor session replay, no personal data. Bugsink receives them (Sentry protocol).
 */
export interface ErrorReportingOptions {
  dsn: string;
  release?: string;
  environment: string;
  sendDefaultPii: false;
  tracesSampleRate: 0;
  ignoreErrors: (string | RegExp)[];
  denyUrls: RegExp[];
  beforeSend: (event: ErrorEvent, hint: EventHint) => ErrorEvent | null;
}

const EMAIL = /[\w.+-]+@[\w-]+\.[\w.-]+/gu;

/** Noise a visitor's browser makes and we cannot fix: dropped connections, extensions, layout loop warnings. */
const IGNORED_ERRORS: (string | RegExp)[] = [
  "ResizeObserver loop",
  "Failed to fetch",
  "NetworkError when attempting to fetch resource",
  "Load failed",
  "The operation was aborted",
  /^AbortError/u,
];

const EXTENSION_URLS = [/^chrome-extension:\/\//u, /^moz-extension:\/\//u, /^safari-(web-)?extension:\/\//u];

function mask(text: string): string {
  return text.replace(EMAIL, "[email]");
}

/** Keeps a request's method and path only: headers, cookies, body and query string can carry tokens. */
export function scrubEvent(event: ErrorEvent): ErrorEvent {
  if (event.request) {
    const url = event.request.url?.split("?")[0];
    event.request = { method: event.request.method, url };
  }
  if (event.message) event.message = mask(event.message);
  for (const exception of event.exception?.values ?? []) {
    if (exception.value) exception.value = mask(exception.value);
  }
  for (const breadcrumb of event.breadcrumbs ?? []) {
    if (breadcrumb.message) breadcrumb.message = mask(breadcrumb.message);
  }
  if (event.user) event.user = event.user.id === undefined ? undefined : { id: event.user.id };

  return event;
}

/** Null when no DSN is configured: the SDK is then not started at all. */
export function errorReportingOptions(dsn: string, release: string, environment: string): ErrorReportingOptions | null {
  if (dsn.trim() === "") return null;

  return {
    dsn: dsn.trim(),
    release: release.trim() === "" ? undefined : release.trim(),
    environment,
    sendDefaultPii: false,
    tracesSampleRate: 0,
    ignoreErrors: IGNORED_ERRORS,
    denyUrls: EXTENSION_URLS,
    beforeSend: (event) => scrubEvent(event),
  };
}
