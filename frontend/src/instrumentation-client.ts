import * as Sentry from "@sentry/nextjs";

import { env } from "@/lib/env";
import { errorReportingOptions } from "@/lib/error-reporting";

// Story 33.32: the browser reports its errors to Bugsink when a DSN was set at build time.
const options = errorReportingOptions(env.sentryDsn, env.sentryRelease, env.sentryEnvironment);
if (options !== null) Sentry.init(options);
