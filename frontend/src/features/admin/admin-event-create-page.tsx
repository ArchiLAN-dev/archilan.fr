"use client";

import { ArrowLeft, CalendarPlus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { EventDraftError, EventForm, type EventFormInput, fieldErrorsFromPayload } from "./admin-event-form";

export function AdminEventCreatePage() {
  const router = useRouter();

  async function handleSubmit(input: EventFormInput) {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/events`, {
      body: JSON.stringify(input),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });

    const payload: unknown = await response.json();

    if (!response.ok) {
      throw new EventDraftError(fieldErrorsFromPayload(payload));
    }

    router.push("/admin/evenements");
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
          <CalendarPlus aria-hidden="true" className="size-6 text-accent-text" />
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
            Nouvel événement
          </h1>
        </div>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          Crée un brouillon. Il restera invisible jusqu'à publication.
        </p>
      </header>

      <div className="rounded-lg border border-border bg-surface p-6">
        <EventForm event={null} mode="create" onSubmit={handleSubmit} />
      </div>
    </section>
  );
}
