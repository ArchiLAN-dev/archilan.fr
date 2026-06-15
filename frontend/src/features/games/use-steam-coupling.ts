"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "@/features/auth/auth-context";
import { saveSteamAccount } from "@/features/auth/steam-account-api";
import { coupleSteamLibrary, type CouplingResult } from "./steam-coupling-api";
import type { SteamCouplingProps } from "./steam-coupling";

const STORAGE_KEY = "archilan.steamProfile";

/**
 * Encapsulates the Steam coupling state shared by the public catalog and the run
 * game-selection page: auto-couple from the saved account / localStorage, inline save,
 * and the matched appids used to badge/filter owned games.
 */
export function useSteamCoupling(): {
  matchedAppIds: Set<number>;
  coupled: boolean;
  couplingProps: SteamCouplingProps;
} {
  const { user, setUser, loading: authLoading } = useAuth();

  const [steamInput, setSteamInput] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<CouplingResult | null>(null);
  const [editing, setEditing] = useState(true);
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
    async (rawValue: string) => {
      const trimmed = rawValue.trim();
      if ("" === trimmed) return;

      setSubmitting(true);
      setSaveMessage(null);

      const coupling = await coupleSteamLibrary(trimmed);
      setResult(coupling);

      if (coupling.outcome === "ok") {
        setEditing(false);
        if (user === null) window.localStorage.setItem(STORAGE_KEY, trimmed);
      }

      setSubmitting(false);
    },
    [user],
  );

  // Pre-fill from the saved account value (or localStorage) and auto-couple once,
  // after auth has settled so a logged-in member uses their saved profile.
  useEffect(() => {
    if (authLoading || autoCoupled.current || dirty.current) return;
    const prefill = user?.steamProfile ?? window.localStorage.getItem(STORAGE_KEY) ?? "";
    if ("" === prefill) return;

    autoCoupled.current = true;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSteamInput(prefill);
    void couple(prefill);
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
      void couple(steamInput);
    },
    onEdit: () => setEditing(true),
    onCancel: () => setEditing(false),
    onSave: () => {
      void handleSave();
    },
  };

  return { matchedAppIds, coupled, couplingProps };
}
