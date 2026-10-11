"use client";

import { useSyncExternalStore } from "react";

const LOCATION_CHANGE = "location-param-change";

function subscribe(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  window.addEventListener(LOCATION_CHANGE, onChange);
  return () => {
    window.removeEventListener("popstate", onChange);
    window.removeEventListener(LOCATION_CHANGE, onChange);
  };
}

/**
 * A query parameter of the current URL, null on the server. Read from the location rather than `useSearchParams`,
 * which takes a statically rendered page out of prerendering (stories 43.8 and 43.9).
 */
export function useLocationParam(name: string): string | null {
  return useSyncExternalStore(
    subscribe,
    () => new URLSearchParams(window.location.search).get(name),
    () => null,
  );
}

/** Sets (or, with null, removes) a query parameter without a navigation, and tells the readers. */
export function replaceLocationParam(name: string, value: string | null): void {
  const url = new URL(window.location.href);
  if (value === null) url.searchParams.delete(name);
  else url.searchParams.set(name, value);
  window.history.replaceState(window.history.state, "", url);
  window.dispatchEvent(new Event(LOCATION_CHANGE));
}
