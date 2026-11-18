import * as Sentry from "@sentry/nextjs";

import { env, serverEnv } from "@/lib/env";
import { errorReportingOptions } from "@/lib/error-reporting";

/** Story 33.32: the Next.js server (Node.js and edge) reports its errors to Bugsink when a DSN is set. */
export function register(): void {
  const options = errorReportingOptions(serverEnv.sentryDsn(), env.sentryRelease, env.sentryEnvironment);
  if (options !== null) Sentry.init(options);
}

export const onRequestError = Sentry.captureRequestError;
