import Image from "next/image";
import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import type { Partner } from "./partners-api";

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
          <Image alt={`Logo de ${partner.name}`} className="max-h-full w-auto object-contain" height={64} src={partner.logoUrl} width={160} />
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
