import { env } from "@/lib/env";

export const externalLinks = {
  twitch: `https://www.twitch.tv/${env.twitchChannelLogin}`,
  archipelagoDiscord: "https://discord.com/invite/8Z65BR2",
  archilanDiscord: "https://discord.gg/bVGmDcv2dE",
} as const;
