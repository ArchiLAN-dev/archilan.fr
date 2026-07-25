import type { SessionRecap } from "./recap-api";
import { formatDuration } from "./recap-format";

// Pure data shaping for the OG share card (story 32.2). The satori-rendered route
// (`opengraph-image.tsx`) must stay dumb: everything selectable/formattable happens here so it can
// be unit-tested without importing `next/og`.

export type ShareCardPodiumEntry = {
  rank: number;
  playerName: string;
  game: string;
  time: string;
};

export type ShareCardData =
  | {
      kind: "recap";
      eventName: string;
      podium: ShareCardPodiumEntry[];
      headline: { label: string; playerName: string } | null;
      playerCount: number;
      duration: string | null;
    }
  | { kind: "fallback" };

const TIME_PLACEHOLDER = "-";

export function buildShareCardData(recap: SessionRecap | null): ShareCardData {
  if (recap === null) return { kind: "fallback" };

  const podium = recap.podium.slice(0, 3).map((slot, index) => ({
    rank: index + 1,
    playerName: slot.playerName,
    game: slot.game,
    time: slot.completionSeconds !== null ? formatDuration(slot.completionSeconds) : TIME_PLACEHOLDER,
  }));

  return {
    kind: "recap",
    eventName: recap.eventName,
    podium,
    headline: pickHeadline(recap),
    playerCount: Math.max(recap.podium.length, recap.graph.nodes.length),
    duration: recap.durationSeconds !== null ? formatDuration(recap.durationSeconds) : null,
  };
}

// First superlative wins the headline spot. Podium names are live display names (RunResultsQuery)
// and take precedence; graph node slotNames are the bridge-reconciled fallback (32.1 decision).
function pickHeadline(recap: SessionRecap): { label: string; playerName: string } | null {
  const superlative = recap.superlatives[0];
  if (!superlative) return null;
  const playerName =
    recap.podium.find((slot) => slot.slotId === superlative.slotId)?.playerName
    ?? recap.graph.nodes.find((node) => node.slotId === superlative.slotId)?.slotName;
  if (playerName === undefined) return null;
  return { label: superlative.label, playerName };
}
