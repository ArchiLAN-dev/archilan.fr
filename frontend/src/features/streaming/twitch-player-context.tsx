"use client";

import { createContext, useCallback, useContext, useState } from "react";

type TwitchPlayerCtx = {
    placeholder: HTMLDivElement | null;
    dismissed: boolean;
    setPlaceholder: (el: HTMLDivElement | null) => void;
    dismiss: () => void;
    undismiss: () => void;
};

const TwitchPlayerContext = createContext<TwitchPlayerCtx>({
    placeholder: null,
    dismissed: false,
    setPlaceholder: () => {},
    dismiss: () => {},
    undismiss: () => {},
});

export function TwitchPlayerProvider({ children }: { children: React.ReactNode }) {
    const [placeholder, setPlaceholderState] = useState<HTMLDivElement | null>(null);
    const [dismissed, setDismissed] = useState(false);

    const setPlaceholder = useCallback((el: HTMLDivElement | null) => {
        setPlaceholderState(el);
        // Retour sur la home = on réaffiche le mini si le stream reprend
        if (el !== null) setDismissed(false);
    }, []);

    const dismiss = useCallback(() => setDismissed(true), []);
    const undismiss = useCallback(() => setDismissed(false), []);

    return (
        <TwitchPlayerContext.Provider value={{ placeholder, dismissed, setPlaceholder, dismiss, undismiss }}>
            {children}
        </TwitchPlayerContext.Provider>
    );
}

export function useTwitchPlayerContext() {
    return useContext(TwitchPlayerContext);
}
