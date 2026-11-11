"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Story 47.4 (spike): a muted WebRTC player that reads one MediaMTX path over WHEP, the brick story 47.8 puts in the
 * régie screen. It sends a recvonly offer once ICE gathering is over (no trickle), keeps the session URL from the
 * `Location` header to close it, and retries after a failure.
 */

export type WhepState = "connecting" | "playing" | "failed";

export type WhepStats = { width: number; height: number; fps: number; jitterMs: number; packetsLost: number; framesDecoded: number };

const RETRY_MS = 3_000;
const GATHERING_TIMEOUT_MS = 2_000;
const STATS_MS = 1_000;
const MAX_PLAYERS = 9;
/** Seconds without a new decoded frame before a connected player counts as cut (WebRTC itself takes about 25 s). */
export const STALL_TICKS = 3;

export type StallWatch = { framesDecoded: number; stillTicks: number };

/** One stats tick: counts the ticks in a row without a new decoded frame. */
export function watchStall(previous: StallWatch, framesDecoded: number): StallWatch {
  return framesDecoded > previous.framesDecoded
    ? { framesDecoded, stillTicks: 0 }
    : { framesDecoded: previous.framesDecoded, stillTicks: previous.stillTicks + 1 };
}

/** The WHEP URLs of `?url=...&url=...`, http(s) only, at most nine. */
export function parseWhepTestUrls(params: URLSearchParams): string[] {
  return params
    .getAll("url")
    .filter((value) => {
      try {
        const url = new URL(value);
        return url.protocol === "https:" || url.protocol === "http:";
      } catch {
        return false;
      }
    })
    .slice(0, MAX_PLAYERS);
}

/** The path's name for the caption: `https://host:8889/p-abc/whep` -> `p-abc`. */
export function whepPathName(whepUrl: string): string {
  try {
    const parts = new URL(whepUrl).pathname.split("/").filter(Boolean);
    return parts.length >= 2 ? (parts[parts.length - 2] ?? whepUrl) : whepUrl;
  } catch {
    return whepUrl;
  }
}

/** What the negotiation needs of an RTCPeerConnection (written out: `Pick` would keep the callback overloads). */
type PeerLike = {
  addTransceiver(kind: "audio" | "video", init: RTCRtpTransceiverInit): unknown;
  createOffer(): Promise<RTCSessionDescriptionInit>;
  setLocalDescription(description: RTCSessionDescriptionInit): Promise<void>;
  setRemoteDescription(description: RTCSessionDescriptionInit): Promise<void>;
  readonly localDescription: { sdp: string } | null;
  readonly iceGatheringState: RTCIceGatheringState;
  addEventListener(type: "icegatheringstatechange", listener: () => void): void;
  removeEventListener(type: "icegatheringstatechange", listener: () => void): void;
};

/** Offers to receive audio and video, posts the offer, applies the answer. Returns the session URL to DELETE, if any. */
export async function negotiateWhep(peer: PeerLike, whepUrl: string, fetcher: typeof fetch = fetch): Promise<string | null> {
  peer.addTransceiver("video", { direction: "recvonly" });
  peer.addTransceiver("audio", { direction: "recvonly" });
  await peer.setLocalDescription(await peer.createOffer());
  await waitForGathering(peer);

  const offer = peer.localDescription?.sdp;
  if (!offer) {
    throw new Error("whep_no_offer");
  }

  const response = await fetcher(whepUrl, { method: "POST", headers: { "Content-Type": "application/sdp" }, body: offer });
  if (response.status !== 201 && response.status !== 200) {
    throw new Error(`whep_status_${response.status}`);
  }
  await peer.setRemoteDescription({ type: "answer", sdp: await response.text() });

  const location = response.headers.get("Location");
  return location ? new URL(location, whepUrl).toString() : null;
}

function waitForGathering(peer: PeerLike): Promise<void> {
  if (peer.iceGatheringState === "complete") {
    return Promise.resolve();
  }
  return new Promise((resolve) => {
    const done = () => {
      peer.removeEventListener("icegatheringstatechange", onChange);
      clearTimeout(timer);
      resolve();
    };
    const onChange = () => {
      if (peer.iceGatheringState === "complete") {
        done();
      }
    };
    const timer = setTimeout(done, GATHERING_TIMEOUT_MS);
    peer.addEventListener("icegatheringstatechange", onChange);
  });
}

function useWhep(whepUrl: string): { videoRef: React.RefObject<HTMLVideoElement | null>; state: WhepState; stats: WhepStats | null } {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const [state, setState] = useState<WhepState>("connecting");
  const [stats, setStats] = useState<WhepStats | null>(null);

  useEffect(() => {
    let stopped = false;
    let peer: RTCPeerConnection | null = null;
    let sessionUrl: string | null = null;
    let retry: ReturnType<typeof setTimeout> | undefined;
    let statsTimer: ReturnType<typeof setInterval> | undefined;

    const close = () => {
      clearInterval(statsTimer);
      peer?.close();
      peer = null;
      // No frozen last image while the player waits for the stream to come back.
      if (videoRef.current) {
        videoRef.current.srcObject = null;
      }
      if (sessionUrl) {
        void fetch(sessionUrl, { method: "DELETE" }).catch(() => undefined);
        sessionUrl = null;
      }
    };

    const fail = () => {
      if (stopped) {
        return;
      }
      close();
      setState("failed");
      setStats(null);
      retry = setTimeout(() => connect(true), RETRY_MS);
    };

    const connect = (isRetry: boolean) => {
      if (stopped) {
        return;
      }
      if (isRetry) {
        setState("connecting");
      }
      const current = new RTCPeerConnection();
      peer = current;
      current.addEventListener("track", (event) => {
        const video = videoRef.current;
        if (video && event.streams[0]) {
          video.srcObject = event.streams[0];
        }
      });
      let connected = false;
      let stall: StallWatch = { framesDecoded: 0, stillTicks: 0 };
      current.addEventListener("connectionstatechange", () => {
        if (current.connectionState === "connected") {
          // "En direct" waits for the first frame: a source MediaMTX has not yet seen go still accepts readers.
          connected = true;
        } else if (current.connectionState === "failed" || current.connectionState === "disconnected") {
          fail();
        }
      });
      statsTimer = setInterval(() => {
        void current.getStats().then((report) => {
          const inbound = readInboundVideo(report);
          setStats(inbound);
          // A player whose source stopped keeps a connected peer for about 25 s: cut it as soon as frames stop.
          stall = watchStall(stall, inbound?.framesDecoded ?? 0);
          if (!connected || peer !== current) {
            return;
          }
          if (stall.stillTicks >= STALL_TICKS) {
            fail();
          } else if (stall.stillTicks === 0 && stall.framesDecoded > 0) {
            setState("playing");
          }
        });
      }, STATS_MS);
      negotiateWhep(current, whepUrl)
        .then((url) => {
          sessionUrl = url;
        })
        .catch(fail);
    };

    connect(false);
    return () => {
      stopped = true;
      clearTimeout(retry);
      close();
    };
  }, [whepUrl]);

  return { videoRef, state, stats };
}

function readInboundVideo(report: RTCStatsReport): WhepStats | null {
  let found: WhepStats | null = null;
  report.forEach((entry: Record<string, unknown>) => {
    if (entry.type === "inbound-rtp" && entry.kind === "video") {
      found = {
        width: Number(entry.frameWidth ?? 0),
        height: Number(entry.frameHeight ?? 0),
        fps: Number(entry.framesPerSecond ?? 0),
        jitterMs: Math.round(Number(entry.jitter ?? 0) * 1000),
        packetsLost: Number(entry.packetsLost ?? 0),
        framesDecoded: Number(entry.framesDecoded ?? 0),
      };
    }
  });
  return found;
}

const STATE_LABELS: Record<WhepState, string> = { connecting: "Connexion...", playing: "En direct", failed: "Coupé, nouvel essai..." };

export function WhepPlayer({ whepUrl, showStats = false, bare = false }: { whepUrl: string; showStats?: boolean; bare?: boolean }) {
  const { videoRef, state, stats } = useWhep(whepUrl);

  // Story 47.8: on the régie screen, the picture alone; the cell draws the player's name.
  if (bare) {
    return <video ref={videoRef} autoPlay muted playsInline className="size-full bg-black object-contain" />;
  }

  return (
    <div className="relative h-full w-full overflow-hidden bg-black">
      <video ref={videoRef} autoPlay muted playsInline className="h-full w-full object-contain" />
      <div className="absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-black/60 px-3 py-1 text-sm text-white">
        <span className="truncate font-semibold">{whepPathName(whepUrl)}</span>
        <span>
          {STATE_LABELS[state]}
          {showStats && stats ? ` · ${stats.width}x${stats.height} · ${Math.round(stats.fps)} i/s · gigue ${stats.jitterMs} ms · perdus ${stats.packetsLost}` : null}
        </span>
      </div>
    </div>
  );
}

/** Story 47.4 (spike): `/o/whep-essai?url=<whep>&url=<whep>...`, a grid of WHEP players with their stats. */
export function WhepTestScreen({ urls }: { urls: string[] }) {
  if (urls.length === 0) {
    return <p className="p-6 text-white">Ajoute un ou plusieurs paramètres `url` (adresse WHEP d&apos;un chemin MediaMTX).</p>;
  }
  const columns = Math.ceil(Math.sqrt(urls.length));
  return (
    <div className="grid h-screen w-screen gap-1 bg-black" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
      {urls.map((url) => (
        <WhepPlayer key={url} whepUrl={url} showStats />
      ))}
    </div>
  );
}
