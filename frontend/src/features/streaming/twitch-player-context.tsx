"use client";

import { createContext, useCallback, useContext, useState } from "react";

type TwitchPlayerContextValue = {
    placeholderEl: HTMLDivElement | null;
    mainPlayerMounted: boolean;
    miniPlayerDismissed: boolean;
    setPlaceholder: (el: HTMLDivElement | null) => void;
    unmountMainPlayer: () => void;
    dismissMiniPlayer: () => void;
    resetDismissed: () => void;
};

const TwitchPlayerContext = createContext<TwitchPlayerContextValue>({
    placeholderEl: null,
    mainPlayerMounted: false,
    miniPlayerDismissed: false,
    setPlaceholder: () => {},
    unmountMainPlayer: () => {},
    dismissMiniPlayer: () => {},
    resetDismissed: () => {},
});

export function TwitchPlayerProvider({ children }: { children: React.ReactNode }) {
    const [placeholderEl, setPlaceholderEl] = useState<HTMLDivElement | null>(null);
    const [mainPlayerMounted, setMainPlayerMounted] = useState(false);
    const [miniPlayerDismissed, setMiniPlayerDismissed] = useState(false);

    const setPlaceholder = useCallback((el: HTMLDivElement | null) => {
        setPlaceholderEl(el);
        setMainPlayerMounted(el !== null);
        if (el !== null) setMiniPlayerDismissed(false);
    }, []);

    const unmountMainPlayer = useCallback(() => {
        setPlaceholderEl(null);
        setMainPlayerMounted(false);
    }, []);

    const dismissMiniPlayer = useCallback(() => {
        setMiniPlayerDismissed(true);
    }, []);

    const resetDismissed = useCallback(() => {
        setMiniPlayerDismissed(false);
    }, []);

    return (
        <TwitchPlayerContext.Provider value={{ placeholderEl, mainPlayerMounted, miniPlayerDismissed, setPlaceholder, unmountMainPlayer, dismissMiniPlayer, resetDismissed }}>
            {children}
        </TwitchPlayerContext.Provider>
    );
}

export function useTwitchPlayerContext() {
    return useContext(TwitchPlayerContext);
}
