"use client";

import { useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export function ForgotPasswordForm() {
  const emailId = useId();
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);

    const formData = new FormData(event.currentTarget);

    try {
      await apiFetch(`${env.apiBaseUrl}/auth/password-reset/request`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: formData.get("email") }),
      });
    } catch {
      // intentional: always show success to prevent email enumeration
    } finally {
      setSubmitting(false);
      setSent(true);
    }
  }

  if (sent) {
    return (
      <div className="rounded-lg border border-border bg-background p-6 text-center">
        <p className="text-sm font-semibold text-foreground">Email envoyé</p>
        <p className="mt-2 text-sm text-muted-foreground">
          Si un compte existe avec cette adresse, tu recevras un lien dans
          quelques instants. Pense à vérifier tes spams.
        </p>
      </div>
    );
  }

  return (
    <div className="grid gap-5 card-glow rounded-lg border border-border p-6">
      <form className="grid gap-5" onSubmit={handleSubmit}>
        <div className="grid gap-2">
          <label
            className="text-sm font-semibold text-foreground"
            htmlFor={emailId}
          >
            Adresse email
          </label>
          <input
            autoComplete="email"
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
            id={emailId}
            name="email"
            required
            type="email"
          />
        </div>

        <button
          className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={submitting}
          type="submit"
        >
          {submitting ? "Envoi en cours…" : "Envoyer le lien de réinitialisation"}
        </button>
      </form>
    </div>
  );
}
