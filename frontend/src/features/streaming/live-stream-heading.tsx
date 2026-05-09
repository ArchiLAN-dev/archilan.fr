"use client";

import { Radio } from "lucide-react";
import { useTwitchStatus } from "@/hooks/use-twitch-status";

export function LiveStreamHeading() {
    const { live, loading } = useTwitchStatus();
    const showDot = !loading && live;

    return (
        <div className="mb-6 flex items-center gap-3">
            {showDot ? (
                <span aria-hidden="true" className="relative flex size-2.5 shrink-0">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-destructive opacity-75" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-destructive" />
                </span>
            ) : (
                <Radio aria-hidden="true" className="size-5 text-accent-text" />
            )}
            <h2 className="font-heading text-2xl font-semibold text-foreground text-on-canvas" id="live-stream-heading">
                ArchiLAN en direct
            </h2>
        </div>
    );
}
