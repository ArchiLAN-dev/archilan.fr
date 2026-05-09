"use client";

import { useEffect, useRef, useState } from "react";
import {
  TWITCH_CONSENT_EVENT,
  TWITCH_CONSENT_GRANTED,
  TWITCH_CONSENT_KEY,
  setTwitchConsent,
  type TwitchConsentDetail,
} from "@/lib/consent";

export function ConsentFooterControl() {
  const [consent, setConsent] = useState<boolean | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const confirmTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    const hydrateTimer = setTimeout(() => {
      const stored = localStorage.getItem(TWITCH_CONSENT_KEY);
      if (stored !== null) {
        setConsent(stored === TWITCH_CONSENT_GRANTED);
      }
    }, 0);

    function showConfirmation() {
      setConfirmed(true);
      if (confirmTimerRef.current) clearTimeout(confirmTimerRef.current);
      confirmTimerRef.current = setTimeout(() => setConfirmed(false), 3000);
    }

    function onConsentChange(event: Event) {
      const { granted } = (event as CustomEvent<TwitchConsentDetail>).detail;
      setConsent(granted);
      showConfirmation();
    }

    function onStorageChange(event: StorageEvent) {
      if (event.key !== TWITCH_CONSENT_KEY || event.newValue === null) {
        return;
      }

      setConsent(event.newValue === TWITCH_CONSENT_GRANTED);
      showConfirmation();
    }

    window.addEventListener(TWITCH_CONSENT_EVENT, onConsentChange);
    window.addEventListener("storage", onStorageChange);

    return () => {
      clearTimeout(hydrateTimer);
      window.removeEventListener(TWITCH_CONSENT_EVENT, onConsentChange);
      window.removeEventListener("storage", onStorageChange);
      if (confirmTimerRef.current) clearTimeout(confirmTimerRef.current);
    };
  }, []);

  if (consent === null) return null;

  return (
    <span className="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
      <span className="text-xs text-muted-foreground">
        Twitch :{" "}
        <strong className="font-medium text-foreground">
          {consent ? "autorise" : "refuse"}
        </strong>
      </span>
      {confirmed && (
        <span aria-live="polite" className="text-xs text-success">
          Preference mise a jour.
        </span>
      )}
      {consent ? (
        <button
          className="min-h-11 text-left text-sm hover:text-foreground"
          onClick={() => setTwitchConsent(false)}
          type="button"
        >
          Retirer le consentement Twitch
        </button>
      ) : (
        <button
          className="min-h-11 text-left text-sm hover:text-foreground"
          onClick={() => setTwitchConsent(true)}
          type="button"
        >
          Autoriser le lecteur Twitch
        </button>
      )}
    </span>
  );
}
