"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminEvents } from "../admin-events-api";
import { eventHubHref } from "./event-status";

export type NavGroup = { title: string; items: { label: string; href: string }[] };

/** Story 3.17: the sections of an event, as the hub lists them. */
export function eventNavGroups(eventId: string): NavGroup[] {
  const base = eventHubHref(eventId);
  return [
    { title: "", items: [{ label: "Vue d'ensemble", href: base }] },
    {
      title: "Préparation",
      items: [
        { label: "Informations", href: `${base}/modifier` },
        { label: "Jeux", href: `${base}/jeux` },
        { label: "Partenaires", href: `${base}/partenaires` },
      ],
    },
    {
      title: "Joueurs",
      items: [
        { label: "Inscriptions", href: `${base}/inscriptions` },
        { label: "Présences", href: `${base}/presences` },
        { label: "Sondages", href: `${base}/sondages` },
        { label: "Pelles", href: `${base}/pelles` },
      ],
    },
    { title: "Jour J", items: [{ label: "Run et sessions", href: `${base}/session` }] },
  ];
}

/** The section a path belongs to: the overview only on its own path, any other section with its sub-pages. */
export function isCurrentSection(href: string, pathname: string, eventId: string): boolean {
  if (href === eventHubHref(eventId)) return pathname === href;
  return pathname === href || pathname.startsWith(`${href}/`);
}

export function AdminEventNav({ eventId }: { eventId: string }) {
  const pathname = usePathname();
  const { data } = useQuery({
    queryKey: ["admin-events"],
    queryFn: fetchAdminEvents,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const title = data?.kind === "ready" ? (data.events.find((event) => event.id === eventId)?.title ?? null) : null;

  return <AdminEventNavView eventId={eventId} pathname={pathname} title={title} />;
}

export function AdminEventNavView({ eventId, pathname, title }: { eventId: string; pathname: string; title: string | null }) {
  const groups = eventNavGroups(eventId);

  return (
    <nav aria-label="Sections de l'événement" className="grid gap-3 lg:sticky lg:top-4 lg:self-start">
      <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href="/admin/evenements">
        <ArrowLeft aria-hidden="true" className="size-4" />
        Événements
      </Link>
      {title ? <p className="font-heading text-lg font-semibold leading-tight text-foreground">{title}</p> : null}
      {/* Mobile: one scrolling row of tabs. Desktop: the groups stacked in a side bar. */}
      <div className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 lg:mx-0 lg:grid lg:gap-4 lg:overflow-visible lg:px-0">
        {groups.map((group) => (
          <div className="contents lg:grid lg:gap-1" key={group.title || "overview"}>
            {group.title ? (
              <p className="hidden text-[11px] font-semibold uppercase tracking-wider text-muted-foreground lg:block">{group.title}</p>
            ) : null}
            {group.items.map((item) => {
              const current = isCurrentSection(item.href, pathname, eventId);
              return (
                <Link
                  aria-current={current ? "page" : undefined}
                  className={`shrink-0 whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition-colors ${
                    current ? "bg-accent/15 font-semibold text-accent-text" : "text-muted-foreground hover:bg-surface-2 hover:text-foreground"
                  }`}
                  href={item.href}
                  key={item.href}
                >
                  {item.label}
                </Link>
              );
            })}
          </div>
        ))}
      </div>
    </nav>
  );
}
