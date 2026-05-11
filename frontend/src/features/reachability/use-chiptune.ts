"use client";

import { useEffect } from "react";
import { FANFARES } from "./fanfares";

const MASTER_GAIN = 0.30;
const ATTACK = 0.006;
const RELEASE = 0.10;

export function useChiptune(): void {
    useEffect(() => {
        const fanfare = FANFARES[Math.floor(Math.random() * FANFARES.length)];
        let ctx: AudioContext | null = null;

        try {
            ctx = new AudioContext();
            const now = ctx.currentTime;
            const master = ctx.createGain();
            master.gain.setValueAtTime(MASTER_GAIN, now);
            master.connect(ctx.destination);

            for (const [freq, start, duration, type, vol] of fanfare) {
                const env = ctx.createGain();

                // Square waves get a 6-cent detuned second oscillator for chiptune thickness
                for (const detune of (type === "square" ? [0, 6] : [0])) {
                    const osc = ctx.createOscillator();
                    osc.type = type;
                    osc.frequency.setValueAtTime(freq, now + start);
                    osc.detune.setValueAtTime(detune, now + start);

                    // Vibrato on notes > 250ms, kicks in after 60ms
                    if (duration > 0.25) {
                        const lfo = ctx.createOscillator();
                        const lfoAmt = ctx.createGain();
                        lfo.type = "sine";
                        lfo.frequency.setValueAtTime(6.5, now + start);
                        lfoAmt.gain.setValueAtTime(0, now + start);
                        lfoAmt.gain.linearRampToValueAtTime(freq * 0.007, now + start + 0.06);
                        lfo.connect(lfoAmt);
                        lfoAmt.connect(osc.frequency);
                        lfo.start(now + start);
                        lfo.stop(now + start + duration + 0.02);
                    }

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
            // AudioContext blocked or unavailable
        }

        return () => { ctx?.close().catch(() => undefined); };
    }, []); // intentional: play once on mount
}
