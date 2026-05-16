"use client";

import { useRouter } from "next/navigation";
import { useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type ResetState = "idle" | "submitting" | "expired";

type ResetErrorResponse = {
  error: { code: string; message: string };
};

function isResetErrorResponse(v: unknown): v is ResetErrorResponse {
  return (
    typeof v === "object" &&
    v !== null &&
    "error" in v &&
    typeof (v as ResetErrorResponse).error?.code === "string"
  );
}

export function ResetPasswordForm({ token }: { token: string }) {
  const passwordId = useId();
  const confirmId = useId();
  const router = useRouter();
  const [state, setState] = useState<ResetState>("idle");
  const [fieldError, setFieldError] = useState<string | null>(null);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFieldError(null);

    const formData = new FormData(event.currentTarget);
    const password = formData.get("password") as string;
    const confirm = formData.get("confirm") as string;

    if (password !== confirm) {
      setFieldError("Les mots de passe ne correspondent pas.");
      return;
    }

    setState("submitting");

    try {
      const response = await apiFetch(
        `${env.apiBaseUrl}/auth/password-reset/confirm`,
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ token, password }),
        },
      );

      if (response.status === 204) {
        router.push("/connexion?reset=1");
        return;
      }

      const payload: unknown = await response.json();
      if (isResetErrorResponse(payload)) {
        setState("expired");
      } else {
        setState("expired");
      }
    } catch {
      setState("expired");
    }
  }

  if (state === "expired") {
    return (
      <div className="rounded-lg border border-border bg-background p-6 text-center">
        <p className="text-sm font-semibold text-foreground">
          Lien invalide ou expiré
        </p>
        <p className="mt-2 text-sm text-muted-foreground">
          Ce lien de réinitialisation n&apos;est plus valide. Il a peut-être
          expiré (15 minutes) ou a déjà été utilisé.
        </p>
        <a
          className="mt-4 inline-block text-sm text-accent-text hover:text-accent-text-hover"
          href="/mot-de-passe-oublie"
        >
          Demander un nouveau lien
        </a>
      </div>
    );
  }

  return (
    <div className="grid gap-5 card-glow rounded-lg border border-border p-6">
      {fieldError && (
        <p
          className="rounded border border-border bg-background p-3 text-sm text-muted-foreground"
          role="alert"
        >
          {fieldError}
        </p>
      )}

      <form className="grid gap-5" onSubmit={handleSubmit}>
        <div className="grid gap-2">
          <label
            className="text-sm font-semibold text-foreground"
            htmlFor={passwordId}
          >
            Nouveau mot de passe
          </label>
          <input
            autoComplete="new-password"
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
            id={passwordId}
            minLength={8}
            name="password"
            required
            type="password"
          />
        </div>

        <div className="grid gap-2">
          <label
            className="text-sm font-semibold text-foreground"
            htmlFor={confirmId}
          >
            Confirmer le mot de passe
          </label>
          <input
            autoComplete="new-password"
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
            id={confirmId}
            minLength={8}
            name="confirm"
            required
            type="password"
          />
        </div>

        <button
          className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={state === "submitting"}
          type="submit"
        >
          {state === "submitting" ? "Réinitialisation…" : "Réinitialiser le mot de passe"}
        </button>
      </form>
    </div>
  );
}
