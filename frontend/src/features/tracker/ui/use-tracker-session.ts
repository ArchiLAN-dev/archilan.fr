"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import type { ArchiveProgress } from "@/features/trackers/tracker-packs-api";

import type { ApEvent, ApOutgoing } from "../engine/ap-events";
import type { EngineAction, LoadedPack, LogLevel } from "../engine/protocol";
import type { TrackerClient } from "../engine/tracker-client";
import { TrackerClientError } from "../engine/tracker-client";
import { ApLink, type ApClientLike, type ApCredentials, type ApLinkStatus } from "./ap-link";
import { PackImages } from "./pack-images";
import { clearSaved, readSaved, restoreActions, snapshot, trackerStorageKey, writeSaved } from "./tracker-storage";
import { EMPTY_VIEW, mergeView, type TrackerView } from "./tracker-view";

/**
 * One tracker session (story 54.5): download the pack (or read it from the browser cache), start the engine in its
 * Worker, put back the state saved in the browser, then apply the player's clicks and save after each one. A new
 * pack, variant or a reset is a new session: the caller changes the component `key`.
 */
export type TrackerSessionSource = {
  versionId: string;
  rootPath: string;
  variantUid: string;
};

export type TrackerSessionDeps = {
  fetchArchive: (versionId: string, onProgress: ArchiveProgress) => Promise<ArrayBuffer | null>;
  createClient: (callbacks: {
    onLog: (log: { level: LogLevel; message: string }) => void;
    onApSend: (packets: ApOutgoing[]) => void;
  }) => TrackerClient;
  /** Story 54.6: one Archipelago connection attempt. */
  createApClient: () => ApClientLike;
};

export type PackMessage = { level: LogLevel; message: string };

export type TrackerSessionPhase =
  | { kind: "download"; loaded: number; total: number | null }
  | { kind: "prepare" }
  | { kind: "ready"; pack: LoadedPack; images: PackImages }
  | { kind: "failed"; message: string };

export type TrackerSession = {
  phase: TrackerSessionPhase;
  view: TrackerView;
  /** Errors the pack reported (decision 9): Lua errors, missing PopTracker functions, widgets not drawn. */
  messages: PackMessage[];
  act: (action: EngineAction) => void;
  reportUnsupported: (type: string) => void;
  /** Forgets the saved state; the caller then starts a new session (a new `key`). */
  forget: () => void;
  /** Story 54.6: autotracking from an Archipelago room. */
  room: ApLinkStatus;
  /** Said once the room overwrote manual clicks (decision 6). */
  roomNotice: string | null;
  connectRoom: (credentials: ApCredentials) => void;
  disconnectRoom: () => void;
};

const MAX_MESSAGES = 200;

function failureMessage(error: unknown): string {
  if (error instanceof TrackerClientError) {
    const where = error.file !== undefined ? ` (${error.file}${error.line !== undefined ? `, ligne ${error.line}` : ""})` : "";
    return `${error.message}${where}`;
  }
  return "Le pack n'a pas pu être chargé.";
}

export function useTrackerSession(source: TrackerSessionSource, deps: TrackerSessionDeps): TrackerSession {
  const [phase, setPhase] = useState<TrackerSessionPhase>({ kind: "download", loaded: 0, total: null });
  const [view, setView] = useState<TrackerView>(EMPTY_VIEW);
  const [messages, setMessages] = useState<PackMessage[]>([]);
  const client = useRef<TrackerClient | null>(null);
  /** The state right after the pack loaded: only what differs from it is saved. */
  const initial = useRef<TrackerView>(EMPTY_VIEW);
  const storageKey = useRef<string | null>(null);
  const { fetchArchive, createClient, createApClient } = deps;
  const [room, setRoom] = useState<ApLinkStatus>({ kind: "idle" });
  const [roomNotice, setRoomNotice] = useState<string | null>(null);
  const link = useRef<ApLink | null>(null);
  /** A manual click since the room last set the state: the next Clear overwrites it, and the screen says so. */
  const manualSinceClear = useRef(false);

  const addMessage = useCallback((message: PackMessage) => {
    setMessages((current) => (current.length >= MAX_MESSAGES ? current : [...current, message]));
  }, []);

  useEffect(() => {
    let cancelled = false;
    let started: TrackerClient | null = null;
    let images: PackImages | null = null;

    async function start() {
      const archive = await fetchArchive(source.versionId, (loaded, total) => {
        if (!cancelled) setPhase({ kind: "download", loaded, total });
      });
      if (cancelled) return;
      if (archive === null) {
        setPhase({ kind: "failed", message: "Le pack n'a pas pu être téléchargé." });
        return;
      }
      setPhase({ kind: "prepare" });
      started = createClient({
        onLog: (log) => {
          if (!cancelled && log.level === "error") addMessage(log);
        },
        onApSend: (packets) => {
          if (!cancelled) link.current?.send(packets);
        },
      });
      const loaded = await started.load(archive, source.rootPath, source.variantUid);
      if (cancelled) return;
      let current = mergeView(EMPTY_VIEW, loaded.state);
      initial.current = current;
      const key = trackerStorageKey(loaded.pack);
      storageKey.current = key;
      const saved = readSaved(key);
      if (saved !== null) {
        const actions = restoreActions(loaded.pack, current, saved);
        if (actions.length > 0) current = mergeView(current, await started.bulk(actions));
      }
      if (cancelled) return;
      const engine = started;
      client.current = engine;
      images = new PackImages((path) => engine.image(path));
      setView(current);
      setPhase({ kind: "ready", pack: loaded.pack, images });
    }

    start().catch((error: unknown) => {
      if (!cancelled) setPhase({ kind: "failed", message: failureMessage(error) });
    });

    return () => {
      cancelled = true;
      link.current?.disconnect();
      link.current = null;
      client.current = null;
      storageKey.current = null;
      started?.dispose();
      images?.dispose();
    };
  }, [source.versionId, source.rootPath, source.variantUid, fetchArchive, createClient, addMessage]);

  const pack = phase.kind === "ready" ? phase.pack : null;

  // While a room drives the tracker, the room holds the state (decision 7): nothing goes to the browser.
  const roomActive = room.kind === "connecting" || room.kind === "connected" || room.kind === "reconnecting";

  // Saved after every change, once the restored state is on screen.
  useEffect(() => {
    const key = storageKey.current;
    if (pack === null || key === null || roomActive) return;
    writeSaved(key, snapshot(pack, initial.current, view));
  }, [pack, view, roomActive]);

  // A stuck pack kills the Worker: the screen cannot go on.
  const fail = useCallback(
    (engine: TrackerClient, error: unknown) => {
      if (client.current !== engine) return;
      if (error instanceof TrackerClientError && (error.kind === "timeout" || error.kind === "worker_failed")) {
        client.current = null;
        link.current?.disconnect();
        setPhase({ kind: "failed", message: error.message });
        return;
      }
      addMessage({ level: "error", message: failureMessage(error) });
    },
    [addMessage],
  );

  const act = useCallback(
    (action: EngineAction) => {
      const engine = client.current;
      if (engine === null) return;
      if (link.current !== null) manualSinceClear.current = true;
      engine
        .apply(action)
        .then((update) => {
          if (client.current === engine) setView((current) => mergeView(current, update));
        })
        .catch((error: unknown) => fail(engine, error));
    },
    [fail],
  );

  const reported = useRef(new Set<string>());
  const reportUnsupported = useCallback(
    (type: string) => {
      if (reported.current.has(type)) return;
      reported.current.add(type);
      addMessage({ level: "warn", message: `Élément d'affichage non géré : ${type}` });
    },
    [addMessage],
  );

  const applyRoom = useCallback(
    (events: ApEvent[]) => {
      const engine = client.current;
      if (engine === null) return;
      if (manualSinceClear.current && events.some((event) => event.kind === "clear")) {
        manualSinceClear.current = false;
        setRoomNotice("La room a remis le tracker à jour : vos clics manuels ont été remplacés.");
      }
      engine
        .ap(events)
        .then((update) => {
          if (client.current === engine) setView((current) => mergeView(current, update));
        })
        .catch((error: unknown) => fail(engine, error));
    },
    [fail],
  );

  const connectRoom = useCallback(
    (credentials: ApCredentials) => {
      if (client.current === null) return;
      link.current?.disconnect();
      const created = new ApLink({
        createClient: createApClient,
        onEvents: applyRoom,
        onStatus: setRoom,
        secure: window.location.protocol === "https:",
      });
      link.current = created;
      manualSinceClear.current = false;
      setRoomNotice(null);
      created.connect(credentials);
    },
    [applyRoom, createApClient],
  );

  const disconnectRoom = useCallback(() => {
    link.current?.disconnect();
    link.current = null;
    setRoom({ kind: "idle" });
  }, []);

  const forget = useCallback(() => {
    link.current?.disconnect();
    link.current = null;
    if (storageKey.current !== null) clearSaved(storageKey.current);
    // No save may follow: the old session is about to unmount.
    storageKey.current = null;
    client.current = null;
  }, []);

  return useMemo(
    () => ({ phase, view, messages, act, reportUnsupported, forget, room, roomNotice, connectRoom, disconnectRoom }),
    [phase, view, messages, act, reportUnsupported, forget, room, roomNotice, connectRoom, disconnectRoom],
  );
}
