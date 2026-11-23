function requireString(name: string, raw: string | undefined): string {
  if (!raw || raw.trim() === "") {
    throw new Error(`Environment variable ${name} is required but not set.`);
  }

  return raw.trim();
}

function optionalString(raw: string | undefined): string {
  return (raw ?? "").trim();
}

function requireUrl(name: string, raw: string | undefined, defaultValue: string): string {
  const value = (raw ?? defaultValue).trim().replace(/\/$/, "");

  if (value !== "" && !value.startsWith("http://") && !value.startsWith("https://")) {
    throw new Error(
      `Environment variable ${name} must start with http:// or https://, got: "${value}"`,
    );
  }

  return value;
}

export const env = {
  apiBaseUrl: requireUrl(
    "NEXT_PUBLIC_API_BASE_URL",
    process.env.NEXT_PUBLIC_API_BASE_URL,
    "http://localhost:8000/api/v1",
  ),
  appUrl: requireUrl(
    "NEXT_PUBLIC_APP_URL",
    process.env.NEXT_PUBLIC_APP_URL,
    "http://localhost:3000",
  ),
  mercurePublicUrl: requireUrl(
    "NEXT_PUBLIC_MERCURE_PUBLIC_URL",
    process.env.NEXT_PUBLIC_MERCURE_PUBLIC_URL,
    "",
  ),
  twitchChannelLogin: requireString(
    "NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN",
    process.env.NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN,
  ),
  archipelagoDiscordUrl: requireUrl(
    "NEXT_PUBLIC_ARCHIPELAGO_DISCORD_URL",
    process.env.NEXT_PUBLIC_ARCHIPELAGO_DISCORD_URL,
    "https://discord.com/invite/8Z65BR2",
  ),
  archilanDiscordUrl: requireUrl(
    "NEXT_PUBLIC_ARCHILAN_DISCORD_URL",
    process.env.NEXT_PUBLIC_ARCHILAN_DISCORD_URL,
    "https://discord.gg/bVGmDcv2dE",
  ),
  // Google Search Console HTML-tag verification token. Empty by default (no tag rendered);
  // set it to verify via the meta-tag method without a code change.
  gscVerification: optionalString(process.env.NEXT_PUBLIC_GSC_VERIFICATION),
  // Story 33.32: where the browser reports its errors (Bugsink, Sentry protocol), and the version it tags them
  // with. Build-time, like every NEXT_PUBLIC_ value. Empty = nothing is sent.
  sentryDsn: optionalString(process.env.NEXT_PUBLIC_SENTRY_DSN),
  sentryRelease: optionalString(process.env.NEXT_PUBLIC_SENTRY_RELEASE),
  // Story 33.34: the version of the image (the release tag, without the v), in a meta tag the deployment script
  // reads. Empty outside a built image.
  appVersion: optionalString(process.env.NEXT_PUBLIC_APP_VERSION),
  // The environment an error report is tagged with. `next build` always sets NODE_ENV to production, so a built
  // image says it itself: production for a release tag, staging for a branch build (docker-publish.yml).
  sentryEnvironment:
    optionalString(process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT) || optionalString(process.env.NODE_ENV) || "development",
} as const;

/**
 * Server-only settings: no `NEXT_PUBLIC_` prefix, so Next.js never inlines them in the client bundle, where
 * they read as empty. Read at call time, from the running server's environment, not at build time.
 */
export const serverEnv = {
  /**
   * Story 34.10: the secret the API sends to `POST /api/revalidate`, the same value as the API's
   * `REVALIDATE_SECRET`. Empty disables the route (503).
   */
  revalidateSecret: (): string => optionalString(process.env.REVALIDATE_SECRET),
  /**
   * Story 33.32: where the Next.js server reports its errors. Read at run time, so the image needs no rebuild;
   * falls back to the browser's DSN baked at build time. Empty = nothing is sent.
   */
  sentryDsn: (): string => optionalString(process.env.SENTRY_DSN) || env.sentryDsn,
} as const;
