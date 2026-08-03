"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "@/features/auth/auth-context";
import { saveSteamAccount } from "@/features/auth/steam-account-api";
import { coupleSteamLibrary, type CouplingOutcome, type CouplingResult } from "./steam-coupling-api";
import type { SteamCouplingProps } from "./steam-coupling";

const STORAGE_KEY = "archilan.steamProfile";

/**
 * Whether a coupling that just happened should switch the "my games" filter on (story 28.11).
 *
 * Two conditions, and the first is the whole point: **only an explicit submit** counts. The hook
 * also couples automatically on every page load from the saved account or localStorage, and acting
 * on that would re-impose the filter on a player who had just turned it off.
 */
export function shouldApplyOwnedFilter(outcome: CouplingOutcome, explicit: boolean): boolean {
  return explicit && "ok" === outcome;
}

type SteamCouplingOptions = {
  /**
   * Called when the player *explicitly* couples their library and it succeeds - never on the
   * automatic pre-fill that runs on each page load (story 28.11).
   */
  onExplicitCouple?: () => void;
};

/**
 * Encapsulates the Steam coupling state shared by the public catalog and the run
 * game-selection page: auto-couple from the saved account / localStorage, inline save,
 * and the matched appids used to badge/filter owned games.
 */
export function useSteamCoupling(options: SteamCouplingOptions = {}): {
  matchedAppIds: Set<number>;
  coupled: boolean;
  /**
   * True once a coupling attempt has finished, or once we know there is nothing to attempt
   * (story 28.12). Callers that clear an owned-games filter when no library is coupled must wait
   * for this: at mount the automatic attempt is still in flight, and acting early would wipe a
   * filter the URL had just restored.
   */
  settled: boolean;
  couplingProps: SteamCouplingProps;
} {
  const { user, setUser, loading: authLoading } = useAuth();
  // Kept in a ref so a caller can pass an inline arrow without re-running the coupling effect,
  // and synced in an effect rather than during render (writing a ref while rendering is banned).
  const onExplicitCouple = useRef(options.onExplicitCouple);
  useEffect(() => {
    onExplicitCouple.current = options.onExplicitCouple;
  }, [options.onExplicitCouple]);

  const [steamInput, setSteamInput] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<CouplingResult | null>(null);
  const [editing, setEditing] = useState(true);
  const [settled, setSettled] = useState(false);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const dirty = useRef(false);
  const autoCoupled = useRef(false);

  const matchedAppIds = useMemo(
    () =>
      result?.outcome === "ok"
        ? new Set(result.matchedGames.map((g) => g.steamAppId))
        : new Set<number>(),
    [result],
  );

  const coupled = matchedAppIds.size > 0;
  const alreadySaved =
    user !== null && "" !== steamInput.trim() && user.steamProfile === steamInput.trim();

  const couple = useCallback(
    async (rawValue: string, explicit: boolean) => {
      const trimmed = rawValue.trim();
      if ("" === trimmed) return;

      setSubmitting(true);
      setSaveMessage(null);

      const coupling = await coupleSteamLibrary(trimmed);
      setResult(coupling);

      if (coupling.outcome === "ok") {
        setEditing(false);
        if (user === null) window.localStorage.setItem(STORAGE_KEY, trimmed);
        if (shouldApplyOwnedFilter(coupling.outcome, explicit)) onExplicitCouple.current?.();
      }

      setSubmitting(false);
      setSettled(true);
    },
    [user],
  );

  // Pre-fill from the saved account value (or localStorage) and auto-couple once,
  // after auth has settled so a logged-in member uses their saved profile.
  useEffect(() => {
    if (authLoading || autoCoupled.current || dirty.current) return;
    const prefill = user?.steamProfile ?? window.localStorage.getItem(STORAGE_KEY) ?? "";
    if ("" === prefill) {
      // Nothing to try: the question is settled, there simply is no library to couple.
      // eslint-disable-next-line react-hooks/set-state-in-effect -- terminal state once auth has settled and no profile exists
      setSettled(true);

      return;
    }

    autoCoupled.current = true;
    // One-shot pre-fill from persisted data (account profile or localStorage) once auth has
    // settled; guarded by the autoCoupled ref. The rule is already disabled above for this effect.
    setSteamInput(prefill);
    void couple(prefill, false);
  }, [authLoading, user, couple]);

  async function handleSave() {
    const trimmed = steamInput.trim();
    if ("" === trimmed) return;
    const saved = await saveSteamAccount(trimmed);
    if (saved.ok && user) {
      setUser({ ...user, steamProfile: trimmed });
    } else {
      setSaveMessage("Impossible d'enregistrer le compte Steam pour le moment.");
    }
  }

  const couplingProps: SteamCouplingProps = {
    view: result?.outcome === "ok" && !editing ? "summary" : "form",
    steamInput,
    submitting,
    result,
    loggedIn: user !== null,
    alreadySaved,
    saveMessage,
    onChange: (v) => {
      dirty.current = true;
      setSteamInput(v);
    },
    onSubmit: (event) => {
      event.preventDefault();
      if (submitting) return;
      void couple(steamInput, true);
    },
    onEdit: () => setEditing(true),
    onCancel: () => setEditing(false),
    onSave: () => {
      void handleSave();
    },
  };

  return { matchedAppIds, coupled, settled, couplingProps };
}
