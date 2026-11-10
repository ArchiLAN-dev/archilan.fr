"use client";

import { CalendarClock, ChevronLeft, ClipboardList, Gamepad2, Handshake, LayoutDashboard, Pencil, Server, Shovel, Users } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminEvents, type AdminEvent } from "../admin-events-api";
import { FeaturedPill, StatusPill } from "./event-actions";
import { eventHubHref, formatDateShort, visibilityLabel } from "./event-status";

export type NavItem = { label: string; href: string; icon: LucideIcon };
export type NavGroup = { title: string; items: NavItem[] };

/** Story 3.17: the sections of an event, as the hub lists them; story 3.18 gives each its icon. */
export function eventNavGroups(eventId: string): NavGroup[] {
  const base = eventHubHref(eventId);
  return [
    { title: "", items: [{ label: "Vue d'ensemble", href: base, icon: LayoutDashboard }] },
    {
      title: "Préparation",
      items: [
        { label: "Informations", href: `${base}/modifier`, icon: Pencil },
        { label: "Jeux", href: `${base}/jeux`, icon: Gamepad2 },
        { label: "Partenaires", href: `${base}/partenaires`, icon: Handshake },
      ],
    },
    {
      title: "Joueurs",
      items: [
        { label: "Inscriptions", href: `${base}/inscriptions`, icon: Users },
        { label: "Présences", href: `${base}/presences`, icon: CalendarClock },
        { label: "Sondages", href: `${base}/sondages`, icon: ClipboardList },
        { label: "Pelles", href: `${base}/pelles`, icon: Shovel },
      ],
    },
    { title: "Jour J", items: [{ label: "Run et sessions", href: `${base}/session`, icon: Server }] },
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
  const event = data?.kind === "ready" ? (data.events.find((candidate) => candidate.id === eventId) ?? null) : null;

  return <AdminEventNavView event={event} eventId={eventId} pathname={pathname} />;
}

/**
 * Story 3.18: the event bar, across the top of every page of an event. Its name and state, then the sections as tabs.
 * Two siblings, so the tabs stay stuck at the top (under the mobile admin header) the whole page long.
 */
export function AdminEventNavView({ event, eventId, pathname }: { event: AdminEvent | null; eventId: string; pathname: string }) {
  const groups = eventNavGroups(eventId);

  return (
    <>
      <div className="grid gap-1 border-b border-border/60 px-4 pt-5 pb-3 md:px-6">
        <Link className="inline-flex w-fit items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground" href="/admin/evenements">
          <ChevronLeft aria-hidden="true" className="size-3.5" />
          Événements
        </Link>
        {event ? (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <p className="font-heading text-xl font-bold leading-tight text-foreground md:text-2xl">{event.title}</p>
            <StatusPill status={event.status} />
            <FeaturedPill event={event} />
            <p className="text-xs text-muted-foreground md:ml-auto">
              <time dateTime={event.startsAt}>{formatDateShort(event.startsAt)}</time>
              {" au "}
              <time dateTime={event.endsAt}>{formatDateShort(event.endsAt)}</time>
              {event.venue ? ` · ${event.venue}` : ""} · {visibilityLabel(event)}
            </p>
          </div>
        ) : (
          <div aria-hidden="true" className="h-7 w-56 animate-pulse rounded bg-surface-2" />
        )}
      </div>
      <nav aria-label="Sections de l'événement" className="sticky top-14 z-30 border-b border-border bg-background/95 backdrop-blur md:top-0">
        <ul className="flex items-stretch overflow-x-auto px-2 md:px-4 [scrollbar-width:none]">
          {groups.map((group, index) => (
            <li className="flex shrink-0 items-stretch" key={group.title || "overview"}>
              {index > 0 ? <span aria-hidden="true" className="mx-1.5 my-2.5 w-px bg-border" data-separator="" /> : null}
              <ul aria-label={group.title || undefined} className="flex items-stretch">
                {group.items.map((item) => {
                  const current = isCurrentSection(item.href, pathname, eventId);
                  const Icon = item.icon;
                  return (
                    <li className="flex" key={item.href}>
                      <Link
                        aria-current={current ? "page" : undefined}
                        className={`-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-3 text-sm transition-colors ${
                          current
                            ? "border-accent font-semibold text-foreground"
                            : "border-transparent text-muted-foreground hover:border-border hover:text-foreground"
                        }`}
                        href={item.href}
                      >
                        <Icon aria-hidden="true" className={`size-4 ${current ? "text-accent-text" : ""}`} />
                        {item.label}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      </nav>
    </>
  );
}
