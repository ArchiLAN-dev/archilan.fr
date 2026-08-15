import { readFile } from "node:fs/promises";
import { join } from "node:path";
import { ImageResponse } from "next/og";

import { getSessionRecap } from "@/features/recap/recap-api.server";
import type { ShareCardPodiumEntry } from "@/features/recap/share-card-data";
import { buildShareCardData } from "@/features/recap/share-card-data";

export const alt = "Récap de partie Archipelago sur ArchiLAN : podium et temps forts";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// Satori renders this route: flexbox only, inline styles only (no Tailwind, no CSS variables -
// documented exception to AC-CSS1). Hex values below mirror the design tokens in
// `src/app/globals.css` (:root palette).
const BG = "#0a1629";
const SURFACE = "#111030";
const BORDER = "#281f52";
const ACCENT_TEXT = "#9580f5";
const ACCENT_WARM = "#e89420";
const TEXT = "#e8edf4";
const TEXT_MUTED = "#7a8ba8";
const TEXT_BODY = "#b8c4d8";

const MEDALS = ["#ffd700", "#c0c0c0", "#cd7f32"];

function PodiumRow({ entry }: { entry: ShareCardPodiumEntry }) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: 20,
        backgroundColor: SURFACE,
        border: `2px solid ${BORDER}`,
        borderRadius: 14,
        padding: "12px 24px",
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          width: 44,
          height: 44,
          borderRadius: 22,
          backgroundColor: MEDALS[entry.rank - 1] ?? BORDER,
          color: "#0a1629",
          fontSize: 26,
          fontWeight: 700,
          fontFamily: "Space Grotesk",
        }}
      >
        {entry.rank}
      </div>
      <div style={{ display: "flex", flexDirection: "column", width: 640 }}>
        <span
          style={{
            fontSize: 34,
            fontWeight: 700,
            fontFamily: "Space Grotesk",
            color: TEXT,
            whiteSpace: "nowrap",
            overflow: "hidden",
            textOverflow: "ellipsis",
          }}
        >
          {entry.playerName}
        </span>
        <span
          style={{
            fontSize: 22,
            color: TEXT_MUTED,
            whiteSpace: "nowrap",
            overflow: "hidden",
            textOverflow: "ellipsis",
          }}
        >
          {entry.game}
        </span>
      </div>
      <span style={{ fontSize: 28, color: ACCENT_TEXT, marginLeft: "auto" }}>{entry.time}</span>
    </div>
  );
}

export default async function Image({ params }: { params: Promise<{ sessionId: string }> }) {
  const { sessionId } = await params;
  const card = buildShareCardData(await getSessionRecap(sessionId));

  const spaceGrotesk = await readFile(
    join(process.cwd(), "src/app/(public)/parties/[sessionId]/SpaceGrotesk-Bold.ttf"),
  );

  const options = {
    ...size,
    fonts: [{ name: "Space Grotesk", data: spaceGrotesk, style: "normal" as const, weight: 700 as const }],
  };

  if (card.kind === "fallback") {
    return new ImageResponse(
      (
        <div
          style={{
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            justifyContent: "center",
            width: "100%",
            height: "100%",
            backgroundColor: BG,
            color: TEXT,
          }}
        >
          <span style={{ fontSize: 40, color: ACCENT_TEXT, fontFamily: "Space Grotesk", fontWeight: 700 }}>
            ArchiLAN
          </span>
          <span style={{ fontSize: 72, fontFamily: "Space Grotesk", fontWeight: 700, marginTop: 20 }}>
            Récap de partie
          </span>
          <span style={{ fontSize: 30, color: TEXT_BODY, marginTop: 20 }}>
            Multiworlds Archipelago, en français - archilan.fr
          </span>
        </div>
      ),
      options,
    );
  }

  return new ImageResponse(
    (
      <div
        style={{
          display: "flex",
          flexDirection: "column",
          width: "100%",
          height: "100%",
          backgroundColor: BG,
          padding: "44px 56px",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
          <span style={{ fontSize: 30, color: ACCENT_TEXT, fontFamily: "Space Grotesk", fontWeight: 700 }}>
            ArchiLAN
          </span>
          <span style={{ fontSize: 24, color: TEXT_MUTED }}>Récap de partie</span>
        </div>

        <span
          style={{
            fontSize: 50,
            lineHeight: 1.2,
            fontFamily: "Space Grotesk",
            fontWeight: 700,
            color: TEXT,
            marginTop: 10,
            whiteSpace: "nowrap",
            overflow: "hidden",
            textOverflow: "ellipsis",
            maxWidth: 1088,
            flexShrink: 0,
          }}
        >
          {card.eventName}
        </span>

        <div style={{ display: "flex", flexDirection: "column", gap: 12, marginTop: 20, flexShrink: 0 }}>
          {card.podium.map((entry) => (
            <PodiumRow entry={entry} key={entry.rank} />
          ))}
        </div>

        {card.headline ? (
          <div
            style={{
              display: "flex",
              alignItems: "center",
              gap: 14,
              marginTop: 20,
              borderLeft: `6px solid ${ACCENT_WARM}`,
              paddingLeft: 18,
              flexShrink: 0,
            }}
          >
            <span
              style={{
                fontSize: 28,
                color: ACCENT_WARM,
                fontFamily: "Space Grotesk",
                fontWeight: 700,
                whiteSpace: "nowrap",
                overflow: "hidden",
                textOverflow: "ellipsis",
                maxWidth: 640,
              }}
            >
              {card.headline.label}
            </span>
            <span
              style={{
                fontSize: 28,
                color: TEXT_BODY,
                whiteSpace: "nowrap",
                overflow: "hidden",
                textOverflow: "ellipsis",
                maxWidth: 400,
              }}
            >
              {card.headline.playerName}
            </span>
          </div>
        ) : null}

        <div style={{ display: "flex", alignItems: "center", gap: 28, marginTop: "auto" }}>
          <span style={{ fontSize: 26, color: TEXT_BODY }}>{card.playerCount} joueurs</span>
          {card.duration ? <span style={{ fontSize: 26, color: TEXT_BODY }}>{card.duration}</span> : null}
          <span style={{ fontSize: 26, color: TEXT_MUTED, marginLeft: "auto" }}>archilan.fr</span>
        </div>
      </div>
    ),
    options,
  );
}
