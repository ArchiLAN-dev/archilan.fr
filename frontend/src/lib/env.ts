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
} as const;
