"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Calendar, Gamepad2, Radio } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "./auth-context";

type RegistrationStatus = "pending" | "confirmed" | "cancelled";

type SessionStatus =
  | "draft" | "validating" | "ready"
  | "generating" | "generated" | "launching"
  | "running" | "stopped" | "failed" | "crashed";

type Registration = {
  registrationId: string;
  eventSlug: string;
  eventTitle: string;
  eventStartDate: string | null;
  registrationStatus: RegistrationStatus;
  slotCount: number;
  sessionStatus: SessionStatus | null;
};

type RegistrationsResponse =
  | { data: Registration[] }
  | { error: { code: string; message: string } };

const REGISTRATION_STATUS_CONFIG: Record<RegistrationStatus, { label: string; className: string }> = {
  pending: {
    label: "En attente",
    className: "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/10 text-[color:var(--color-accent-warm)]",
  },
  confirmed: {
    label: "Confirmée",
    className: "border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)]",
  },
  cancelled: {
    label: "Annulée",
    className: "border-border bg-surface text-muted-foreground",
  },
};

const SESSION_STATUS_LABELS: Record<SessionStatus, string> = {
  draft: "En préparation",
  validating: "Validation en cours",
  ready: "Prête",
  generating: "Génération en cours",
  generated: "Générée",
  launching: "Lancement en cours",
  running: "En cours",
  stopped: "Arrêtée",
  failed: "Échec",
  crashed: "Plantée",
};

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

export function AccountRegistrations() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [registrations, setRegistrations] = useState<Registration[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/connexion?returnTo=/compte");
      return;
    }

    let mounted = true;

    async function load() {
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/account/registrations`);
        if (!mounted) return;
        if (!res.ok) { setError(true); return; }
        const payload = (await res.json()) as RegistrationsResponse;
        if ("error" in payload) { setError(true); return; }
        setRegistrations(payload.data);
      } catch {
        if (mounted) setError(true);
      } finally {
        if (mounted) setLoading(false);
      }
    }

    void load();
    return () => { mounted = false; };
  }, [authLoading, user, router]);

  if (authLoading || loading) {
    return (
      <section>
        <h2 className="mb-4 font-heading text-xl font-bold text-foreground">Mes inscriptions</h2>
        <div className="grid gap-3">
          {[1, 2].map((i) => (
            <div key={i} className="h-24 animate-pulse rounded-lg border border-border bg-surface" />
          ))}
        </div>
      </section>
    );
  }

  if (error) {
    return (
      <section>
        <h2 className="mb-4 font-heading text-xl font-bold text-foreground">Mes inscriptions</h2>
        <p className="text-sm text-muted-foreground">
          Impossible de charger tes inscriptions pour le moment.
        </p>
      </section>
    );
  }

  if (!registrations || registrations.length === 0) {
    return (
      <section>
        <h2 className="mb-4 font-heading text-xl font-bold text-foreground">Mes inscriptions</h2>
        <div className="rounded-lg border border-border bg-surface p-6 text-center">
          <p className="text-sm text-muted-foreground">
            Tu n&apos;as pas encore d&apos;inscription.{" "}
            <Link className="text-accent-text hover:text-accent-text-hover" href="/evenements">
              Voir les événements
            </Link>
          </p>
        </div>
      </section>
    );
  }

  return (
    <section>
      <h2 className="mb-4 font-heading text-xl font-bold text-foreground">Mes inscriptions</h2>
      <div className="grid gap-3">
        {registrations.map((reg) => {
          const statusConfig = REGISTRATION_STATUS_CONFIG[reg.registrationStatus];
          const isActive = reg.registrationStatus !== "cancelled";
          const isLive = reg.sessionStatus === "running";
          const hasSession = reg.sessionStatus !== null;
          const sessionLabel = reg.sessionStatus ? SESSION_STATUS_LABELS[reg.sessionStatus] : null;

          return (
            <div
              key={reg.registrationId}
              className={[
                "rounded-lg border border-border bg-surface px-5 py-4 transition-colors",
                isActive && "hover:border-border/80",
                !isActive && "opacity-60",
              ].filter(Boolean).join(" ")}
            >
              <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-2">
                  {/* Title + badges */}
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-heading font-semibold text-foreground">
                      {reg.eventTitle}
                    </span>
                    <span className={`inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium ${statusConfig.className}`}>
                      {statusConfig.label}
                    </span>
                    {isLive && (
                      <span className="inline-flex items-center gap-1.5 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/10 px-2 py-0.5 text-xs font-medium text-[color:var(--color-danger)]">
                        <span className="relative flex size-1.5 shrink-0">
                          <span className="absolute inline-flex size-full animate-ping rounded-full bg-[color:var(--color-danger)] opacity-75" />
                          <span className="relative inline-flex size-1.5 rounded-full bg-[color:var(--color-danger)]" />
                        </span>
                        Live
                      </span>
                    )}
                  </div>

                  {/* Meta */}
                  <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                    {reg.eventStartDate && (
                      <span className="flex items-center gap-1.5">
                        <Calendar aria-hidden className="size-3.5" />
                        {formatDate(reg.eventStartDate)}
                      </span>
                    )}
                    {reg.slotCount > 0 && (
                      <span className="flex items-center gap-1.5">
                        <Gamepad2 aria-hidden className="size-3.5" />
                        {reg.slotCount} {reg.slotCount === 1 ? "jeu sélectionné" : "jeux sélectionnés"}
                      </span>
                    )}
                    {sessionLabel && !isLive && (
                      <span className="flex items-center gap-1.5">
                        <Radio aria-hidden className="size-3.5" />
                        Session : {sessionLabel}
                      </span>
                    )}
                  </div>
                </div>

                {/* Actions */}
                {isActive && (
                  <div className="flex shrink-0 flex-wrap gap-2">
                    <Link
                      className="inline-flex min-h-9 items-center rounded border border-border px-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                      href={`/evenements/${reg.eventSlug}/inscription/${reg.registrationId}/jeux`}
                    >
                      Mes jeux
                    </Link>
                    {hasSession && (
                      <Link
                        className={[
                          "inline-flex min-h-9 items-center rounded px-3 text-sm font-semibold transition-colors",
                          isLive
                            ? "btn-glow bg-accent text-white hover:bg-accent-hover"
                            : "border border-border text-muted-foreground hover:border-accent hover:text-foreground",
                        ].join(" ")}
                        href={`/evenements/${reg.eventSlug}/inscription/${reg.registrationId}/session`}
                      >
                        Ma session
                      </Link>
                    )}
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
