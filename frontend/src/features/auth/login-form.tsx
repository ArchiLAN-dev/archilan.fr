"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "./auth-context";
import type { AuthUser } from "./auth-context";

type AuthResponse =
  | {
      data: AuthUser;
      meta: {
        message?: string;
      };
    }
  | {
      error: {
        code: string;
        message: string;
        details: Record<string, string[]>;
      };
    };

const AUTH_PAGES = ["/connexion", "/inscription"];

function hasProp<K extends string>(obj: object, key: K): obj is Record<K, unknown> {
  return key in obj;
}

function isAuthResponse(v: unknown): v is AuthResponse {
  if (typeof v !== "object" || v === null) return false;
  if (hasProp(v, "error")) {
    const { error } = v;
    if (typeof error !== "object" || error === null || !hasProp(error, "message")) return false;
    return typeof error.message === "string";
  }
  return hasProp(v, "data");
}

export function LoginForm({ returnTo }: { returnTo?: string }) {
  const emailId = useId();
  const passwordId = useId();
  const rememberMeId = useId();
  const router = useRouter();
  const { setUser } = useAuth();
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    setSubmitting(true);
    setMessage(null);

    const formData = new FormData(form);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/auth/login`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          email: formData.get("email"),
          password: formData.get("password"),
          rememberMe: formData.get("rememberMe") === "on",
        }),
      });
      const payload: unknown = await response.json();

      if (!isAuthResponse(payload)) {
        setMessage("Impossible de se connecter pour le moment. Réessaie dans quelques instants.");
        return;
      }

      if ("error" in payload) {
        setMessage(payload.error.message);
        return;
      }

      setUser(payload.data);
      const destination = returnTo && !AUTH_PAGES.includes(returnTo) ? returnTo : "/";
      router.push(destination);
    } catch {
      setMessage("Impossible de se connecter pour le moment. Réessaie dans quelques instants.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="grid gap-5 card-glow rounded-lg border border-border p-6">
      {message && (
        <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="alert">
          {message}
        </p>
      )}

      <form className="grid gap-5" onSubmit={handleSubmit}>
        <div className="grid gap-2">
          <label className="text-sm font-semibold text-foreground" htmlFor={emailId}>
            Email
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

        <div className="grid gap-2">
          <div className="flex items-baseline justify-between">
            <label className="text-sm font-semibold text-foreground" htmlFor={passwordId}>
              Mot de passe
            </label>
            <Link
              className="text-xs text-accent-text hover:text-accent-text-hover"
              href="/mot-de-passe-oublie"
            >
              Mot de passe oublié ?
            </Link>
          </div>
          <input
            autoComplete="current-password"
            className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
            id={passwordId}
            name="password"
            required
            type="password"
          />
        </div>

        <div className="flex items-center gap-2">
          <input
            className="h-4 w-4 rounded border border-border bg-background accent-accent"
            defaultChecked
            id={rememberMeId}
            name="rememberMe"
            type="checkbox"
          />
          <label className="text-sm text-muted-foreground" htmlFor={rememberMeId}>
            Se souvenir de moi
          </label>
        </div>

        <button
          className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={submitting}
          type="submit"
        >
          {submitting ? "Connexion..." : "Se connecter"}
        </button>

        <p className="text-sm text-muted-foreground">
          Pas encore de compte ?{" "}
          <Link className="text-accent-text hover:text-accent-text-hover" href="/inscription">
            Créer un compte
          </Link>
        </p>
      </form>
    </div>
  );
}
