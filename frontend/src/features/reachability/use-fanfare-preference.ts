"use client";

import { useCallback, useState } from "react";
import type { FanfareId } from "./fanfares";

export type FanfarePref = FanfareId | "random" | "disabled";

const STORAGE_KEY = "archilan_fanfare";
const VALID: FanfarePref[] = ["bowser", "ff5", "smg", "alttp", "sa2", "p5", "random", "disabled"];

export function readFanfarePref(): FanfarePref {
    if (typeof window === "undefined") return "random";
    const v = localStorage.getItem(STORAGE_KEY);
    return VALID.includes(v as FanfarePref) ? (v as FanfarePref) : "random";
}

export function useFanfarePreference(): [FanfarePref, (pref: FanfarePref) => void] {
    const [pref, setPrefState] = useState<FanfarePref>(() => readFanfarePref());

    const setPref = useCallback((next: FanfarePref) => {
        setPrefState(next);
        localStorage.setItem(STORAGE_KEY, next);
    }, []);

    return [pref, setPref];
}
