"use client";

import { useState } from "react";
import { Ban, Clock, History, Loader2, MessageSquareWarning, Undo2 } from "lucide-react";

import {
  type AccountActionEntry,
  banAccount,
  fetchAccountActions,
  liftAccount,
  type ModerationActionKind,
  suspendAccount,
  warnAccount,
} from "./admin-moderation-api";

const ACTION_LABELS: Record<string, string> = {
  warn: "Averti",
  suspend: "Suspendu",
  ban: "Banni",
  lift: "Sanction levée",
};

export function AccountModerationControls({
  userId,
  name,
  onActed,
}: {
  userId: string;
  name: string;
  onActed: () => void;
}) {
  const [mode, setMode] = useState<ModerationActionKind | null>(null);
  const [reason, setReason] = useState("");
  const [until, setUntil] = useState("");
  const [busy, setBusy] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [history, setHistory] = useState<AccountActionEntry[] | null>(null);

  function open(next: ModerationActionKind) {
    setMode(next);
    setReason("");
    setUntil("");
  }

  async function submit() {
    if (mode === null) return;
    setBusy(true);
    let ok = false;
    if (mode === "warn") ok = await warnAccount(userId, reason);
    else if (mode === "ban") ok = await banAccount(userId, reason);
    else if (mode === "lift") ok = await liftAccount(userId, reason);
    else if (mode === "suspend") ok = await suspendAccount(userId, new Date(until).toISOString(), reason);
    setBusy(false);
    if (ok) {
      setMode(null);
      onActed();
    }
  }

  async function toggleHistory() {
    const next = !historyOpen;
    setHistoryOpen(next);
    if (next && history === null) {
      setHistory(await fetchAccountActions(userId));
    }
  }

  const canSubmit = mode === "lift" || (reason.trim() !== "" && (mode !== "suspend" || until !== ""));

  return (
    <div className="grid gap-2">
      <div className="flex flex-wrap gap-1.5">
        <ActionButton icon={<MessageSquareWarning className="size-3.5" />} label="Avertir" onClick={() => open("warn")} />
        <ActionButton icon={<Clock className="size-3.5" />} label="Suspendre" onClick={() => open("suspend")} />
        <ActionButton icon={<Ban className="size-3.5" />} danger label="Bannir" onClick={() => open("ban")} />
        <ActionButton icon={<Undo2 className="size-3.5" />} label="Lever" onClick={() => open("lift")} />
        <ActionButton icon={<History className="size-3.5" />} label="Historique" onClick={() => void toggleHistory()} />
      </div>

      {mode !== null ? (
        <div className="grid gap-2 rounded-md border border-border bg-background/60 p-2.5">
          <p className="text-xs font-medium text-foreground">
            {mode === "warn" && `Avertir ${name}`}
            {mode === "suspend" && `Suspendre ${name}`}
            {mode === "ban" && `Bannir ${name}`}
            {mode === "lift" && `Lever la sanction de ${name}`}
          </p>
          {mode === "suspend" ? (
            <label className="grid gap-1 text-xs text-muted-foreground">
              Jusqu&apos;au
              <input
                className="min-h-8 rounded border border-border bg-background px-2 text-sm text-foreground"
                min={new Date().toISOString().slice(0, 10)}
                onChange={(e) => setUntil(e.target.value)}
                type="date"
                value={until}
              />
            </label>
          ) : null}
          {mode !== "lift" ? (
            <textarea
              className="min-h-14 rounded border border-border bg-background px-2 py-1 text-sm text-foreground"
              onChange={(e) => setReason(e.target.value)}
              placeholder="Motif (visible par le joueur)"
              value={reason}
            />
          ) : null}
          <div className="flex justify-end gap-2">
            <button className="rounded border border-border px-2.5 py-1 text-xs text-muted-foreground hover:text-foreground" onClick={() => setMode(null)} type="button">
              Annuler
            </button>
            <button
              className="inline-flex items-center gap-1 rounded bg-accent px-2.5 py-1 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
              disabled={busy || !canSubmit}
              onClick={() => void submit()}
              type="button"
            >
              {busy ? <Loader2 className="size-3.5 animate-spin" /> : null} Confirmer
            </button>
          </div>
        </div>
      ) : null}

      {historyOpen ? (
        <div className="rounded-md border border-border bg-background/60 p-2.5 text-xs">
          {history === null ? (
            <span className="text-muted-foreground">Chargement…</span>
          ) : history.length === 0 ? (
            <span className="text-muted-foreground">Aucune action enregistrée.</span>
          ) : (
            <ul className="grid gap-1" role="list">
              {history.map((entry) => (
                <li className="flex items-baseline justify-between gap-2" key={entry.id}>
                  <span>
                    <strong className="text-foreground">{ACTION_LABELS[entry.action] ?? entry.action}</strong>
                    {entry.reason ? <span className="text-muted-foreground"> — {entry.reason}</span> : null}
                  </span>
                  <time className="shrink-0 text-muted-foreground" dateTime={entry.createdAt}>
                    {new Date(entry.createdAt).toLocaleDateString("fr-FR")}
                  </time>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}

function ActionButton({ icon, label, onClick, danger = false }: { icon: React.ReactNode; label: string; onClick: () => void; danger?: boolean }) {
  return (
    <button
      className={`inline-flex items-center gap-1 rounded border px-2 py-1 text-xs font-medium transition-colors ${
        danger ? "border-red-500/40 text-red-400 hover:bg-red-500/10" : "border-border text-muted-foreground hover:text-foreground hover:border-accent"
      }`}
      onClick={onClick}
      type="button"
    >
      {icon} {label}
    </button>
  );
}
