"use client";

import Link from "next/link";
import { useId, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

const CGU_VERSION_LABEL = "2 mai 2026";

type SignupField = "email" | "password" | "acceptedCgu";
type FieldErrors = Partial<Record<SignupField, string[]>>;

type RegisterResponse =
  | {
      data: {
        id: string;
        email: string;
        roles: string[];
      };
      meta: {
        message: string;
      };
    }
  | {
      error: {
        code: string;
        message: string;
        details: FieldErrors;
      };
    };

export function SignupForm() {
  const emailId = useId();
  const passwordId = useId();
  const cguId = useId();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [formMessageType, setFormMessageType] = useState<"error" | "success">("error");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    setSubmitting(true);
    setFieldErrors({});
    setFormMessage(null);

    const formData = new FormData(form);

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/accounts/register`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          email: formData.get("email"),
          password: formData.get("password"),
          acceptedCgu: formData.get("acceptedCgu") === "on",
        }),
      });
      const payload = (await response.json()) as RegisterResponse;

      if ("error" in payload) {
        setFieldErrors(payload.error.details);
        setFormMessageType("error");
        setFormMessage(payload.error.message);
        return;
      }

      form.reset();
      setFormMessageType("success");
      setFormMessage("Compte créé. Tu pourras te connecter dès que la session sera activée.");
    } catch {
      setFormMessage("Impossible de créer le compte pour le moment. Réessaie dans quelques instants.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="grid gap-5 card-glow rounded-lg border border-border p-6" onSubmit={handleSubmit}>
      <FieldErrorSummary message={formMessage} type={formMessageType} />

      <div className="grid gap-2">
        <label className="text-sm font-semibold text-foreground" htmlFor={emailId}>
          Email
        </label>
        <input
          aria-describedby={fieldErrors.email ? `${emailId}-error` : undefined}
          aria-invalid={fieldErrors.email ? true : undefined}
          autoComplete="email"
          className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
          id={emailId}
          name="email"
          required
          type="email"
        />
        <FieldError errors={fieldErrors.email} id={`${emailId}-error`} />
      </div>

      <div className="grid gap-2">
        <label className="text-sm font-semibold text-foreground" htmlFor={passwordId}>
          Mot de passe
        </label>
        <input
          aria-describedby={fieldErrors.password ? `${passwordId}-error ${passwordId}-hint` : `${passwordId}-hint`}
          aria-invalid={fieldErrors.password ? true : undefined}
          autoComplete="new-password"
          className="min-h-12 rounded border border-border bg-background px-3 text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
          id={passwordId}
          minLength={12}
          name="password"
          required
          type="password"
        />
        <p className="text-sm text-muted-foreground" id={`${passwordId}-hint`}>
          Minimum 12 caractères.
        </p>
        <FieldError errors={fieldErrors.password} id={`${passwordId}-error`} />
      </div>

      <div className="grid gap-2">
        <label className="flex items-start gap-3 text-sm text-muted-foreground" htmlFor={cguId}>
          <input
            aria-describedby={fieldErrors.acceptedCgu ? `${cguId}-error` : undefined}
            aria-invalid={fieldErrors.acceptedCgu ? true : undefined}
            className="mt-1 size-4 rounded border-border text-accent-text"
            id={cguId}
            name="acceptedCgu"
            type="checkbox"
          />
          <span>
            J&apos;accepte les{" "}
            <Link className="text-accent-text hover:text-accent-text-hover" href="/cgu">
              conditions d&apos;utilisation
            </Link>{" "}
            d&apos;ArchiLAN, version du {CGU_VERSION_LABEL}.
          </span>
        </label>
        <FieldError errors={fieldErrors.acceptedCgu} id={`${cguId}-error`} />
      </div>

      <button
        className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
        disabled={submitting}
        type="submit"
      >
        {submitting ? "Création..." : "Créer mon compte"}
      </button>
    </form>
  );
}

function FieldErrorSummary({ message, type = "error" }: { message: string | null; type?: "error" | "success" }) {
  if (!message) {
    return null;
  }

  const className = type === "success"
    ? "rounded border border-[color:var(--color-success)]/50 bg-background p-3 text-sm text-[color:var(--color-success)]"
    : "rounded border border-danger/50 bg-background p-3 text-sm text-danger";

  return (
    <p className={className} role={type === "success" ? "status" : "alert"}>
      {message}
    </p>
  );
}

function FieldError({ errors, id }: { errors?: string[]; id: string }) {
  if (!errors || errors.length === 0) {
    return null;
  }

  return (
    <p className="text-sm text-danger" id={id}>
      {errors[0]}
    </p>
  );
}
