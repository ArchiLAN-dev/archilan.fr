"use client";

import { useState } from "react";
import { resendEmailConfirmation } from "./auth-api";

export function EmailVerificationBanner() {
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);

  async function handleResend() {
    setSending(true);
    await resendEmailConfirmation();
    setSending(false);
    setSent(true);
  }

  return (
    <div
      className="flex flex-col gap-3 rounded-lg border border-yellow-500/40 bg-yellow-500/10 p-4 text-sm sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <p className="text-foreground">
        <span className="font-semibold">Email non confirmé.</span>{" "}
        Confirme ton adresse email pour t&apos;inscrire aux événements ArchiLAN.
      </p>

      {sent ? (
        <p className="shrink-0 text-muted-foreground">Email envoyé !</p>
      ) : (
        <button
          className="shrink-0 inline-flex items-center justify-center rounded border border-border bg-background px-4 py-1.5 text-sm font-semibold text-foreground transition-colors hover:bg-surface disabled:cursor-not-allowed disabled:opacity-60"
          disabled={sending}
          type="button"
          onClick={handleResend}
        >
          {sending ? "Envoi…" : "Renvoyer le lien"}
        </button>
      )}
    </div>
  );
}
