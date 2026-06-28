import { env } from "@/lib/env";

export const externalLinks = {
  twitch: `https://www.twitch.tv/${env.twitchChannelLogin}`,
  archipelagoDiscord: env.archipelagoDiscordUrl,
  archilanDiscord: env.archilanDiscordUrl,
} as const;
