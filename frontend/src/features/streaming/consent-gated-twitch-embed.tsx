"use client";

import { useEffect, useRef } from "react";
import { ExternalLink, Radio } from "lucide-react";
import { externalLinks } from "@/lib/external-links";
import { useTwitchStatus } from "@/hooks/use-twitch-status";
import { useTwitchPlayerContext } from "./twitch-player-context";

export function ConsentGatedTwitchEmbed() {
    const { live } = useTwitchStatus();
    const { setPlaceholder } = useTwitchPlayerContext();
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!live) return;
        setPlaceholder(ref.current);
        return () => setPlaceholder(null);
    }, [live, setPlaceholder]);

    if (!live) {
        return (
            <div className="card-glow rounded-lg border border-border p-6 text-center">
                <div className="mx-auto flex size-12 items-center justify-center rounded-full border border-border bg-background">
                    <Radio aria-hidden="true" className="size-5 text-muted-foreground" />
                </div>
                <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">
                    Aucun live en cours
                </h3>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Retrouve ArchiLAN sur Twitch pour les prochains événements.
                </p>
                <a
                    aria-label="Voir la chaîne Twitch ArchiLAN (nouvel onglet)"
                    className="mt-6 inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                    href={externalLinks.twitch}
                    rel="noopener noreferrer"
                    target="_blank"
                >
                    Voir la chaîne Twitch
                    <ExternalLink aria-hidden="true" className="size-4" />
                </a>
            </div>
        );
    }

    // Le vrai player est un iframe fixe positionné par TwitchPersistentPlayer.
    // Ce div sert uniquement de référence pour calculer la position inline.
    return (
        <div className="grid gap-2 w-[85%] mx-auto">
            <div
                ref={ref}
                className="aspect-video w-full rounded-lg border border-border bg-surface"
            />
        </div>
    );
}
