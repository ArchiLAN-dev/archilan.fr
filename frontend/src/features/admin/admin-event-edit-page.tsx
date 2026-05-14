"use client";

import { ArrowLeft, Pencil, ShieldAlert } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import {
  type AdminEventFormData,
  EventDraftError,
  EventForm,
  type EventFormInput,
  fieldErrorsFromPayload,
} from "./admin-event-form";

type PageState =
  | { kind: "loading" }
  | { kind: "ready"; event: AdminEventFormData }
  | { kind: "not-found" }
  | { kind: "error"; message: string };

export function AdminEventEditPage({ eventId }: { eventId: string }) {
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const router = useRouter();

  useEffect(() => {
    let cancelled = false;

    async function loadEvent() {
      try {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}`);

        if (cancelled) return;

        if (response.status === 404) {
          setState({ kind: "not-found" });
          return;
        }

        if (response.status === 401 || response.status === 403) {
          setState({ kind: "error", message: "Accès réservé aux admins ArchiLAN." });
          return;
        }

        if (!response.ok) {
          setState({ kind: "error", message: "Impossible de charger l'événement." });
          return;
        }

        const payload: unknown = await response.json();
        const event = extractEventData(payload);

        if (!event) {
          setState({ kind: "error", message: "Réponse API invalide." });
          return;
        }

        setState({ kind: "ready", event });
      } catch {
        if (!cancelled) {
          setState({ kind: "error", message: "Impossible de contacter l'API." });
        }
      }
    }

    void loadEvent();
    return () => { cancelled = true; };
  }, [eventId]);

  async function handleSubmit(input: EventFormInput) {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}`, {
      body: JSON.stringify(input),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });

    const payload: unknown = await response.json();

    if (!response.ok) {
      throw new EventDraftError(fieldErrorsFromPayload(payload));
    }

    router.push("/admin/evenements");
  }

  if (state.kind === "loading") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="animate-pulse">
          <div className="h-4 w-32 rounded bg-surface-2" />
          <div className="mt-6 h-3.5 w-20 rounded bg-surface-2" />
          <div className="mt-2 h-9 w-80 rounded bg-surface-2" />
        </div>
        <div className="animate-pulse rounded-lg border border-border bg-surface p-6">
          <div className="grid gap-4 md:grid-cols-2">
            {Array.from({ length: 9 }).map((_, i) => (
              <div className="grid gap-2" key={i}>
                <div className="h-3 w-24 rounded bg-surface-2" />
                <div className="h-11 rounded bg-surface-2" />
              </div>
            ))}
          </div>
          <div className="mt-4 h-28 rounded bg-surface-2" />
          <div className="mt-4 h-28 rounded bg-surface-2" />
        </div>
      </section>
    );
  }

  if (state.kind === "not-found" || state.kind === "error") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
          <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
          <p className="text-sm text-muted-foreground">
            {state.kind === "not-found" ? "Événement introuvable." : state.message}
          </p>
          <Link className="text-sm text-accent-text hover:underline" href="/admin/evenements">
            Retour aux événements
          </Link>
        </div>
      </section>
    );
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <Link
          className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          href="/admin/evenements"
        >
          <ArrowLeft aria-hidden="true" className="size-3.5" />
          Retour aux événements
        </Link>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex items-center gap-3">
          <Pencil aria-hidden="true" className="size-6 text-accent-text" />
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
            {state.event.title}
          </h1>
        </div>
      </header>

      <div className="rounded-lg border border-border bg-surface p-6">
        <EventForm
          event={state.event}
          mode="edit"
          onEventUpdated={(event) => setState({ kind: "ready", event })}
          onSubmit={handleSubmit}
        />
      </div>
    </section>
  );
}

function extractEventData(payload: unknown): AdminEventFormData | null {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  if (!data || typeof data !== "object" || !("id" in data) || !("title" in data)) {
    return null;
  }

  return data as AdminEventFormData;
}
