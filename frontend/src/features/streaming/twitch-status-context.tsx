"use client";

import { createContext, useContext } from "react";
import { useQuery } from "@tanstack/react-query";
import { SESSION_STALE_TIME } from "@/lib/query-client";
import { fetchTwitchLiveStatus } from "./streaming-api";

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
    // Poll the channel status. The queryFn throws on failure (instead of returning null)
    // so TanStack keeps the last known data across a failed refetch and flags isError -
    // same "stale status + error flag" behaviour as the previous manual poller.
    const { data, isPending, isError } = useQuery({
        queryKey: ["twitch-live-status"],
        queryFn: async ({ signal }) => {
            const status = await fetchTwitchLiveStatus(signal);
            if (status === null) throw new Error("twitch-live-status-unavailable");
            return status;
        },
        staleTime: SESSION_STALE_TIME, // 60 s - matches the poll cadence
        refetchInterval: POLL_INTERVAL_MS,
    });

    const value: TwitchStatus = {
        live: data?.live ?? false,
        viewerCount: data?.viewerCount ?? null,
        loading: isPending,
        error: isError,
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
