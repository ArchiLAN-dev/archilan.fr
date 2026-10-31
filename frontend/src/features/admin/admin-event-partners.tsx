"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowLeft, ArrowUp, Handshake, Plus, X } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import {
  fetchAdminEventPartners,
  fetchAdminPartners,
  saveEventPartners,
  type AdminPartner,
  type EventPartnerRef,
} from "@/features/content/partners-api";
import { ADMIN_PARTNERS_KEY, partnerStateLabel } from "@/features/admin/admin-partners";
import { PartnerScreenLinks } from "@/features/admin/partner-screen-links";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

/** The API's ceiling, mirrored to disable « Ajouter » instead of failing on save. */
export const MAX_EVENT_PARTNERS = 5;
export const MENTION_MAX_LENGTH = 120;

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

export function addEventPartner(refs: readonly EventPartnerRef[], partnerId: string): EventPartnerRef[] {
  if (refs.length >= MAX_EVENT_PARTNERS || refs.some((ref) => ref.partnerId === partnerId)) return [...refs];
  return [...refs, { partnerId, mention: null }];
}

export function removeEventPartner(refs: readonly EventPartnerRef[], partnerId: string): EventPartnerRef[] {
  return refs.filter((ref) => ref.partnerId !== partnerId);
}

/** The refs with the one at `from` moved by `delta` (-1 up, +1 down); unchanged at the ends. */
export function moveEventPartner(refs: readonly EventPartnerRef[], from: number, delta: -1 | 1): EventPartnerRef[] {
  const to = from + delta;
  if (from < 0 || from >= refs.length || to < 0 || to >= refs.length) return [...refs];
  const next = [...refs];
  [next[from], next[to]] = [next[to], next[from]];
  return next;
}

/** An empty mention is sent as null: the API stores none. */
export function withMention(refs: readonly EventPartnerRef[], partnerId: string, mention: string): EventPartnerRef[] {
  return refs.map((ref) => (ref.partnerId === partnerId ? { ...ref, mention: mention.trim() === "" ? null : mention } : ref));
}

/** Story 45.3: the partners thanked on one event, with an optional mention each. */
export function AdminEventPartnersPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const partners = useQuery({ queryKey: ADMIN_PARTNERS_KEY, queryFn: fetchAdminPartners, staleTime: DEFAULT_STALE_TIME, retry: false });
  const chosen = useQuery({
    queryKey: ["admin-event-partners", eventId],
    queryFn: () => fetchAdminEventPartners(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const isLoading = partners.isLoading || chosen.isLoading;
  const failed = !isLoading && (partners.data === null || chosen.data === null);

  return (
    <div className="grid gap-6 p-6 md:p-8">
      <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href="/admin/evenements">
        <ArrowLeft aria-hidden className="size-4" />
        Événements
      </Link>
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Partenaires de l&apos;événement</h1>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
          Ils sont remerciés sur la page de l&apos;événement et à la fin du récap de ses parties. Seuls les partenaires affichés (avec un logo)
          apparaissent sur le site.
        </p>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {failed ? <p className="text-sm text-danger">Impossible de charger les partenaires de cet événement.</p> : null}
      {partners.data && chosen.data ? (
        // The draft starts from the first read; a refetch after saving leaves it (and its confirmation) alone.
        <EventPartnersEditor eventId={eventId} initial={chosen.data} partners={partners.data} />
      ) : null}
      {/* Story 45.4: the event's screen for OBS and the LAN. */}
      <PartnerScreenLinks eventId={eventId} />
    </div>
  );
}

type EditorProps = { eventId: string; initial: EventPartnerRef[]; partners: AdminPartner[] };

export function EventPartnersEditor({ eventId, initial, partners }: EditorProps) {
  const queryClient = useQueryClient();
  const [refs, setRefs] = useState<EventPartnerRef[]>(initial);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const byId = new Map(partners.map((partner) => [partner.id, partner]));
  const available = partners.filter((partner) => !refs.some((ref) => ref.partnerId === partner.id));
  const full = refs.length >= MAX_EVENT_PARTNERS;

  function change(next: EventPartnerRef[]): void {
    setRefs(next);
    setSaved(false);
  }

  async function save(): Promise<void> {
    setPending(true);
    const message = await saveEventPartners(eventId, refs);
    setPending(false);
    setError(message);
    setSaved(message === null);
    if (message === null) await queryClient.invalidateQueries({ queryKey: ["admin-event-partners", eventId] });
  }

  if (partners.length === 0) {
    return (
      <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
        <Handshake aria-hidden className="size-5" />
        Aucun partenaire pour l&apos;instant :{" "}
        <Link className="font-semibold text-accent-text hover:underline" href="/admin/partenaires">
          en ajouter un
        </Link>
        .
      </p>
    );
  }

  return (
    <div className="grid gap-6">
      <section aria-labelledby="choisis-heading" className="grid gap-3">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="choisis-heading">
          Choisis ({refs.length}/{MAX_EVENT_PARTNERS})
        </h2>
        {refs.length === 0 ? <p className="text-sm text-muted-foreground">Aucun partenaire pour cet événement.</p> : null}
        <ul className="grid gap-3">
          {refs.map((ref, index) => {
            const partner = byId.get(ref.partnerId);
            return (
              <li className="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[1fr_auto] sm:items-center" key={ref.partnerId}>
                <div className="grid gap-2">
                  <p className="text-sm font-semibold text-foreground">
                    {partner?.name ?? "Partenaire supprimé"}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                      {partner !== undefined ? partnerStateLabel(partner) : "n'apparaît plus"}
                    </span>
                  </p>
                  <label className="grid gap-1 text-xs text-muted-foreground">
                    Mention (facultative), à la place de sa description
                    <input
                      className={fieldClass}
                      maxLength={MENTION_MAX_LENGTH}
                      onChange={(event) => change(withMention(refs, ref.partnerId, event.target.value))}
                      placeholder="Lots offerts par..."
                      value={ref.mention ?? ""}
                    />
                  </label>
                </div>
                <div className="flex gap-1">
                  <button
                    aria-label={`Monter ${partner?.name ?? "ce partenaire"}`}
                    className={buttonVariants({ variant: "ghost" })}
                    disabled={index === 0}
                    onClick={() => change(moveEventPartner(refs, index, -1))}
                    type="button"
                  >
                    <ArrowUp aria-hidden className="size-4" />
                  </button>
                  <button
                    aria-label={`Descendre ${partner?.name ?? "ce partenaire"}`}
                    className={buttonVariants({ variant: "ghost" })}
                    disabled={index === refs.length - 1}
                    onClick={() => change(moveEventPartner(refs, index, 1))}
                    type="button"
                  >
                    <ArrowDown aria-hidden className="size-4" />
                  </button>
                  <button
                    aria-label={`Retirer ${partner?.name ?? "ce partenaire"}`}
                    className={buttonVariants({ variant: "ghost" })}
                    onClick={() => change(removeEventPartner(refs, ref.partnerId))}
                    type="button"
                  >
                    <X aria-hidden className="size-4" />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      </section>

      {available.length > 0 ? (
        <section aria-labelledby="disponibles-heading" className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground" id="disponibles-heading">
            Autres partenaires
          </h2>
          {full ? <p className="text-sm text-muted-foreground">Un événement compte au plus {MAX_EVENT_PARTNERS} partenaires.</p> : null}
          <ul className="flex flex-wrap gap-2">
            {available.map((partner) => (
              <li key={partner.id}>
                <button
                  className={buttonVariants({ variant: "secondary" })}
                  disabled={full}
                  onClick={() => change(addEventPartner(refs, partner.id))}
                  type="button"
                >
                  <Plus aria-hidden className="size-4" />
                  {partner.name}
                  <span className="text-xs text-muted-foreground">{partnerStateLabel(partner)}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <div className="flex flex-wrap items-center gap-3">
        <button className={buttonVariants({ variant: "primary" })} disabled={pending} onClick={() => void save()} type="button">
          {pending ? "Enregistrement…" : "Enregistrer"}
        </button>
        {saved ? (
          <p className="text-sm text-success" role="status">
            Partenaires enregistrés.
          </p>
        ) : null}
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </div>
    </div>
  );
}
