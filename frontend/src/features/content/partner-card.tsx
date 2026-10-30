import Image from "next/image";
import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import type { EventPartner, Partner } from "./partners-api";

type Props = { partner: Partner };

/**
 * Story 45.2: a partner on the home page and on `/partenaires`. The logo sits on a white chip whatever the theme: a
 * partner's logo is almost always drawn for a light background, and a dark one vanished on the dark theme (45.1).
 * The chip color is fixed on purpose, not a theme token. The link says it comes from a partnership (`sponsored`).
 */
export function PartnerCard({ partner }: Props) {
  return (
    <a
      className="group grid h-full content-start gap-4 rounded-2xl border border-border bg-surface p-5 transition-colors hover:border-accent"
      href={partner.url}
      rel="sponsored noopener noreferrer"
      target="_blank"
    >
      <span className="flex h-24 items-center justify-center rounded-xl bg-white p-4">
        {partner.logoUrl !== null ? (
          <Image alt={`Logo de ${partner.name}`} className="max-h-full max-w-full w-auto object-contain" height={64} src={partner.logoUrl} width={160} />
        ) : null}
      </span>
      <span className="grid gap-1">
        <span className="flex items-center gap-1 font-heading text-lg font-semibold text-foreground">
          {partner.name}
          <ArrowUpRight aria-hidden className="size-4 text-muted-foreground transition-colors group-hover:text-accent-text" />
        </span>
        {partner.description !== "" ? <span className="text-sm leading-6 text-muted-foreground">{partner.description}</span> : null}
      </span>
    </a>
  );
}

/** One column on a phone, up to three on a wide screen; sized for the five partners the site is laid out for. */
export function PartnerGrid({ partners }: { partners: Partner[] }) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list">
      {partners.map((partner) => (
        <li key={partner.id}>
          <PartnerCard partner={partner} />
        </li>
      ))}
    </ul>
  );
}

/** The footer's text link to `/partenaires`, after its separator; nothing when no partner is shown. */
export function PartnersFooterLink({ show }: { show: boolean }) {
  if (!show) return null;
  return (
    <>
      <span aria-hidden="true" className="select-none text-muted-foreground/40">
        ·
      </span>
      <Link className="inline-flex items-center hover:text-foreground" href="/partenaires">
        Partenaires
      </Link>
    </>
  );
}

type EventPartnersProps = {
  partners: EventPartner[];
  /** `page`: the event page's block; `discreet`: a quiet line of logos at the end of a party's recap. */
  variant: "page" | "discreet";
};

/**
 * Story 45.3: the partners thanked on an event, with the event's mention ("Lots offerts par...") in place of the
 * partner's own description when there is one. Nothing at all without a visible partner.
 */
export function EventPartners({ partners, variant }: EventPartnersProps) {
  if (partners.length === 0) return null;

  if (variant === "discreet") {
    return (
      <aside aria-labelledby="soutiens-partie-heading" className="grid gap-3 border-t border-border pt-6">
        <h2 className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground" id="soutiens-partie-heading">
          Avec le soutien de
        </h2>
        <ul className="flex flex-wrap gap-3" role="list">
          {partners.map(({ partner, mention }) => (
            <li key={partner.id}>
              <a
                className="flex items-center gap-3 rounded-xl border border-border bg-surface py-2 pl-2 pr-4 text-sm text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                href={partner.url}
                rel="sponsored noopener noreferrer"
                target="_blank"
              >
                <SmallLogoChip partner={partner} />
                <span className="grid">
                  <span className="font-semibold text-foreground">{partner.name}</span>
                  {mention !== null ? <span className="text-xs">{mention}</span> : null}
                </span>
              </a>
            </li>
          ))}
        </ul>
      </aside>
    );
  }

  return (
    <section aria-labelledby="soutiens-evenement-heading" className="grid gap-4">
      <h2 className="font-heading text-2xl font-bold text-foreground" id="soutiens-evenement-heading">
        Avec le soutien de
      </h2>
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list">
        {partners.map(({ partner, mention }) => (
          <li key={partner.id}>
            <PartnerCard partner={mention !== null ? { ...partner, description: mention } : partner} />
          </li>
        ))}
      </ul>
    </section>
  );
}

/** The fixed white chip of {@link PartnerCard}, small enough for a line of logos. */
function SmallLogoChip({ partner }: { partner: Partner }) {
  return (
    <span className="flex h-10 w-16 shrink-0 items-center justify-center rounded-lg bg-white p-1.5">
      {partner.logoUrl !== null ? (
        <Image alt={`Logo de ${partner.name}`} className="max-h-full max-w-full w-auto object-contain" height={28} src={partner.logoUrl} width={56} />
      ) : null}
    </span>
  );
}
