"use client";

import {useState} from "react";
import {asGameRequiredFiles, requiredFileLabel, type GameRequiredFile} from "@/features/games/required-files";
import {clearRequiredFileNeed, type AdminGame} from "./admin-games-api";

type Props = {
    gameId: string;
    // The game's `requiredFiles` as the payload carries it, validated here.
    requiredFiles: unknown;
    onUpdate: (game: AdminGame) => void;
};

/** How a file reads on the admin screen (story 44.3): mandatory at launch, or only declared. */
export function requiredFileStatus(file: GameRequiredFile): { label: string; tone: "warning" | "muted" } {
    return file.neededAtGeneration
        ? {label: "Exigé à la génération", tone: "warning"}
        : {label: "Déclaré, optionnel", tone: "muted"};
}

/**
 * Story 44.3: the files the world reads from the player (a ROM most often). A declared file is only offered
 * to the launcher; a generation that failed without it makes it mandatory, until an admin says otherwise.
 */
export function AdminGameRequiredFiles({gameId, requiredFiles, onUpdate}: Props) {
    const files = asGameRequiredFiles(requiredFiles);
    const [pending, setPending] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    if (files.length === 0) {
        return null;
    }

    async function clearNeed(setting: string) {
        setError(null);
        setPending(setting);
        const updated = await clearRequiredFileNeed(gameId, setting);
        setPending(null);
        if (updated === null) {
            setError("Impossible de retirer l'exigence.");
            return;
        }
        onUpdate(updated);
    }

    return (
        <section className="grid min-w-0 gap-4 rounded-lg border border-border bg-surface p-5 sm:p-6" aria-labelledby="required-files-title">
            <div className="grid gap-1">
                <h2 className="font-heading text-lg font-semibold text-foreground" id="required-files-title">Fichiers du joueur</h2>
                <p className="text-sm text-muted-foreground">
                    Ce que le world lit chez le joueur, une ROM le plus souvent. ArchiLAN ne les stocke jamais : le lanceur les
                    fournit à chaque génération. Seuls les fichiers exigés à la génération lui seront demandés.
                </p>
            </div>
            <ul className="grid gap-3">
                {files.map((file) => {
                    const status = requiredFileStatus(file);
                    return (
                        <li className="grid gap-1 rounded border border-border bg-background p-3" key={file.setting}>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-semibold text-foreground">{requiredFileLabel(file)}</span>
                                {file.extension !== "" ? <span className="text-sm text-muted-foreground">{file.extension}</span> : null}
                                <span className={status.tone === "warning"
                                    ? "rounded bg-accent-warm/15 px-2 py-0.5 text-xs font-semibold text-accent-warm"
                                    : "rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"}>
                                    {status.label}
                                </span>
                            </div>
                            <p className="break-all text-xs text-muted-foreground">
                                {file.setting} · {file.file}
                            </p>
                            {file.md5s.length > 0 ? (
                                <p className="break-all font-mono text-xs text-muted-foreground">MD5 : {file.md5s.join(", ")}</p>
                            ) : null}
                            {file.neededAtGeneration ? (
                                <div>
                                    <button
                                        className="mt-1 text-sm font-semibold text-accent-text underline-offset-2 hover:underline disabled:opacity-50"
                                        disabled={pending !== null}
                                        onClick={() => void clearNeed(file.setting)}
                                        type="button"
                                    >
                                        {pending === file.setting ? "Retrait…" : "Ne plus l'exiger"}
                                    </button>
                                </div>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
            {error ? <p className="text-sm text-danger">{error}</p> : null}
        </section>
    );
}
