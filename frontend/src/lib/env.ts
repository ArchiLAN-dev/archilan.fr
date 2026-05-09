function requireString(name: string, raw: string | undefined): string {
  if (!raw || raw.trim() === "") {
    throw new Error(`Environment variable ${name} is required but not set.`);
  }

  return raw.trim();
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
} as const;
