"use client";

import { Music, Play, Shuffle, VolumeX, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { FANFARE_IDS, FANFARE_NAMES, FANFARES_MAP, type NoteSpec } from "./fanfares";
import { type FanfarePref, useFanfarePreference } from "./use-fanfare-preference";

const MASTER_GAIN = 0.22;
const ATTACK = 0.006;
const RELEASE = 0.10;

function fanfareDuration(notes: NoteSpec[]): number {
    return Math.max(...notes.map(([, start, duration]) => start + duration));
}

function playPreview(notes: NoteSpec[]): () => void {
    let ctx: AudioContext | null = null;
    try {
        ctx = new AudioContext();
        const now = ctx.currentTime;
        const master = ctx.createGain();
        master.gain.setValueAtTime(MASTER_GAIN, now);
        master.connect(ctx.destination);

        for (const [freq, start, duration, type, vol] of notes) {
            const env = ctx.createGain();
            for (const detune of (type === "square" ? [0, 6] : [0])) {
                const osc = ctx.createOscillator();
                osc.type = type;
                osc.frequency.setValueAtTime(freq, now + start);
                osc.detune.setValueAtTime(detune, now + start);
                osc.connect(env);
                osc.start(now + start);
                osc.stop(now + start + duration + 0.02);
            }
            const gainVol = type === "square" ? vol * 0.55 : vol;
            env.gain.setValueAtTime(0, now + start);
            env.gain.linearRampToValueAtTime(gainVol, now + start + ATTACK);
            if (duration > ATTACK + RELEASE) {
                env.gain.setValueAtTime(gainVol, now + start + duration - RELEASE);
                env.gain.exponentialRampToValueAtTime(0.001, now + start + duration);
            } else {
                env.gain.exponentialRampToValueAtTime(0.001, now + start + duration);
            }
            env.connect(master);
        }
    } catch {
        // AudioContext unavailable
    }
    return () => { ctx?.close().catch(() => undefined); };
}

export function FanfarePicker() {
    const [pref, setPref] = useFanfarePreference();
    const [open, setOpen] = useState(false);
    const [playing, setPlaying] = useState<FanfarePref | null>(null);
    const stopRef = useRef<(() => void) | null>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        function onKey(e: KeyboardEvent) { if (e.key === "Escape") { stopCurrent(); setOpen(false); } }
        function onPointer(e: MouseEvent) {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) { stopCurrent(); setOpen(false); }
        }
        document.addEventListener("keydown", onKey);
        document.addEventListener("mousedown", onPointer);
        return () => {
            document.removeEventListener("keydown", onKey);
            document.removeEventListener("mousedown", onPointer);
        };
    }, [open]);

    function stopCurrent() {
        stopRef.current?.();
        stopRef.current = null;
        setPlaying(null);
    }

    function handlePreview(id: FanfarePref) {
        stopCurrent();
        if (id === "random" || id === "disabled") return;
        setPlaying(id);
        const notes = FANFARES_MAP[id];
        const stop = playPreview(notes);
        stopRef.current = stop;
        setTimeout(() => {
            setPlaying((cur) => (cur === id ? null : cur));
            if (stopRef.current === stop) stopRef.current = null;
        }, fanfareDuration(notes) * 1000 + 200);
    }

    function handleSelect(id: FanfarePref) {
        setPref(id);
        stopCurrent();
        setOpen(false);
    }

    const label = pref === "disabled" ? "Désactivée" : pref === "random" ? "Aléatoire" : FANFARE_NAMES[pref];

    return (
        <div className="relative" ref={panelRef}>
            <button
                aria-expanded={open}
                aria-haspopup="listbox"
                aria-label={`Fanfare de victoire : ${label}`}
                className="inline-flex h-8 items-center gap-1.5 rounded border border-border bg-surface px-2.5 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                type="button"
                onClick={() => setOpen((v) => !v)}
            >
                {pref === "disabled"
                    ? <VolumeX aria-hidden="true" className="size-3.5 shrink-0" />
                    : <Music aria-hidden="true" className="size-3.5 shrink-0" />
                }
                <span className="max-w-[120px] truncate">{label}</span>
            </button>

            {open && (
                <div
                    className="absolute right-0 top-full z-50 mt-1 w-56 rounded border border-border bg-surface shadow-lg"
                    role="listbox"
                    aria-label="Fanfare de victoire"
                >
                    <div className="flex items-center justify-between border-b border-border px-3 py-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Fanfare victoire</span>
                        <button
                            aria-label="Fermer"
                            className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                            type="button"
                            onClick={() => { stopCurrent(); setOpen(false); }}
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>

                    <ul className="py-1">
                        {/* Random option */}
                        <li>
                            <button
                                aria-selected={pref === "random"}
                                className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-surface-2 ${pref === "random" ? "text-accent-text font-semibold" : "text-foreground"}`}
                                role="option"
                                type="button"
                                onClick={() => handleSelect("random")}
                            >
                                <Shuffle aria-hidden="true" className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="flex-1">Aléatoire</span>
                                {pref === "random" && <span className="text-xs text-accent-text">✓</span>}
                            </button>
                        </li>

                        {/* Disabled option */}
                        <li>
                            <button
                                aria-selected={pref === "disabled"}
                                className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-surface-2 ${pref === "disabled" ? "text-accent-text font-semibold" : "text-foreground"}`}
                                role="option"
                                type="button"
                                onClick={() => handleSelect("disabled")}
                            >
                                <VolumeX aria-hidden="true" className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="flex-1">Désactivée</span>
                                {pref === "disabled" && <span className="text-xs text-accent-text">✓</span>}
                            </button>
                        </li>

                        <li aria-hidden="true" className="my-1 border-t border-border" />

                        {FANFARE_IDS.map((id) => (
                            <li key={id}>
                                <div className={`flex items-center gap-1 px-3 py-1.5 transition-colors hover:bg-surface-2 ${pref === id ? "bg-surface-2" : ""}`}>
                                    <button
                                        aria-selected={pref === id}
                                        className={`flex-1 text-left text-sm ${pref === id ? "font-semibold text-accent-text" : "text-foreground"}`}
                                        role="option"
                                        type="button"
                                        onClick={() => handleSelect(id)}
                                    >
                                        {FANFARE_NAMES[id]}
                                    </button>
                                    <button
                                        aria-label={`Écouter ${FANFARE_NAMES[id]}`}
                                        className={`shrink-0 rounded p-1 transition-colors ${playing === id ? "text-accent-text" : "text-muted-foreground hover:text-foreground"}`}
                                        type="button"
                                        onClick={() => playing === id ? stopCurrent() : handlePreview(id)}
                                    >
                                        {playing === id
                                            ? <span className="block size-3 animate-pulse rounded-sm bg-accent" aria-hidden="true" />
                                            : <Play aria-hidden="true" className="size-3" />
                                        }
                                    </button>
                                    {pref === id && <span className="ml-0.5 text-xs text-accent-text">✓</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
