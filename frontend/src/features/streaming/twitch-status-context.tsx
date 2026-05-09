"use client";

import { createContext, useContext, useEffect, useRef, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type TwitchStatusData = {
    live: boolean;
    viewerCount: number | null;
};

export type TwitchStatus = {
    live: boolean;
    viewerCount: number | null;
    loading: boolean;
    error: boolean;
};

const POLL_INTERVAL_MS = 60_000;

const TwitchStatusContext = createContext<TwitchStatus>({
    live: false,
    viewerCount: null,
    loading: true,
    error: false,
});

export function TwitchStatusProvider({ children }: { children: React.ReactNode }) {
    const [data, setData] = useState<TwitchStatusData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        let mounted = true;

        async function pollStatus(): Promise<void> {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const res = await apiFetch(`${env.apiBaseUrl}/live/status`, { signal: controller.signal });
                if (!mounted) return;
                if (!res.ok) throw new Error("not ok");
                const payload = (await res.json()) as unknown;
                const status = (payload as { data?: TwitchStatusData }).data;
                if (status && typeof status.live === "boolean") {
                    setData(status);
                    setError(false);
                }
            } catch (e) {
                if ((e as Error).name === "AbortError") return;
                if (mounted) setError(true);
            } finally {
                if (mounted) setLoading(false);
            }
        }

        void pollStatus();
        const timer = setInterval(() => void pollStatus(), POLL_INTERVAL_MS);

        return () => {
            mounted = false;
            abortRef.current?.abort();
            clearInterval(timer);
        };
    }, []);

    const value: TwitchStatus = {
        live: data?.live ?? false,
        viewerCount: data?.viewerCount ?? null,
        loading,
        error,
    };

    return (
        <TwitchStatusContext.Provider value={value}>
            {children}
        </TwitchStatusContext.Provider>
    );
}

export function useTwitchStatus(): TwitchStatus {
    return useContext(TwitchStatusContext);
}
