"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { ArrowRight, X } from "lucide-react";
import { Dialog as RadixDialog } from "radix-ui";

import { Markdown } from "@/components/markdown/markdown";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import type { HomeAnnouncement } from "./home-announcement-api";

/** A beat after the page shows, so the modal never competes with the first paint. */
const OPEN_AFTER_MS = 800;
/** setTimeout overflows past ~24.8 days; the API only hands an announcement starting within a day anyway. */
const MAX_WAIT_MS = 2 ** 31 - 1;

/**
 * When to open the announcement, read with the browser's clock (the page itself can be five minutes old, ISR): in
 * `ms` from now, 0 while it runs, null once it is over.
 */
export function openDelayMs(announcement: Pick<HomeAnnouncement, "startsAt" | "endsAt">, now: number): number | null {
  const startsAt = new Date(announcement.startsAt).getTime();
  const endsAt = new Date(announcement.endsAt).getTime();
  if (Number.isNaN(startsAt) || Number.isNaN(endsAt) || now >= endsAt) return null;
  return Math.max(0, startsAt - now);
}

export function seenKey(id: string): string {
  return `home-announcement-seen:${id}`;
}

/** Storage can be missing or throw (private window, blocked site data): then the modal shows once per page load. */
function hasSeen(id: string): boolean {
  try {
    return window.localStorage.getItem(seenKey(id)) !== null;
  } catch {
    return false;
  }
}

function markSeen(id: string): void {
  try {
    window.localStorage.setItem(seenKey(id), new Date().toISOString());
  } catch {
    // Nothing to remember it with: it stays closed until the next page load.
  }
}

export function isExternalLink(url: string): boolean {
  return url.startsWith("https://");
}

/**
 * Story 10.14: the announcement opened as a modal on the home page between its two dates, once per browser. A click
 * beside it, Escape or the cross close it; its button leads to the page it announces.
 */
export function HomeAnnouncementModal({ announcement }: { announcement: HomeAnnouncement | null }) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (announcement === null || hasSeen(announcement.id)) return;
    const delay = openDelayMs(announcement, Date.now());
    if (delay === null || delay > MAX_WAIT_MS) return;
    const timer = window.setTimeout(() => {
      if (openDelayMs(announcement, Date.now()) !== null) setOpen(true);
    }, Math.max(delay, OPEN_AFTER_MS));
    return () => window.clearTimeout(timer);
  }, [announcement]);

  if (announcement === null) return null;

  function close(): void {
    if (announcement !== null) markSeen(announcement.id);
    setOpen(false);
  }

  return (
    <RadixDialog.Root
      onOpenChange={(next) => {
        if (!next) close();
      }}
      open={open}
    >
      <HomeAnnouncementContent announcement={announcement} onFollow={close} />
    </RadixDialog.Root>
  );
}

/** The modal itself, also shown by the admin preview. */
export function HomeAnnouncementContent({ announcement, onFollow }: { announcement: HomeAnnouncement; onFollow?: () => void }) {
  const ctaClass = cn(buttonVariants({ variant: "primary" }), "min-h-11 w-full px-5 text-base sm:w-auto");
  const external = isExternalLink(announcement.ctaUrl);

  return (
    <RadixDialog.Portal>
      <RadixDialog.Overlay className="fixed inset-0 z-50 bg-black/70 backdrop-blur-[2px] data-[state=open]:animate-in data-[state=open]:fade-in-0" />
      <RadixDialog.Content
        {...(announcement.body === "" ? { "aria-describedby": undefined } : {})}
        className="fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-2xl focus:outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
      >
        <RadixDialog.Close
          aria-label="Fermer"
          className="absolute right-3 top-3 z-10 rounded-full bg-background/80 p-1.5 text-muted-foreground backdrop-blur transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
        >
          <X aria-hidden className="size-5" />
        </RadixDialog.Close>
        {announcement.imageUrl !== null ? (
          <div className="relative aspect-[16/9] w-full shrink-0 bg-background">
            <Image
              alt=""
              className="object-cover"
              fill
              sizes="(max-width: 640px) 100vw, 32rem"
              src={announcement.imageUrl}
              // The admin preview shows a file not uploaded yet (blob URL), which the optimizer cannot fetch.
              unoptimized={announcement.imageUrl.startsWith("blob:")}
            />
          </div>
        ) : null}
        <div className="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto px-5 pb-2 pt-5 sm:px-6">
          <RadixDialog.Title className="pr-8 font-heading text-2xl font-bold text-foreground">{announcement.title}</RadixDialog.Title>
          {announcement.body !== "" ? (
            <RadixDialog.Description asChild>
              <div className="text-sm text-muted-foreground">
                <Markdown>{announcement.body}</Markdown>
              </div>
            </RadixDialog.Description>
          ) : null}
        </div>
        <div className="shrink-0 px-5 pb-5 pt-3 sm:px-6">
          {external ? (
            <a className={ctaClass} href={announcement.ctaUrl} onClick={onFollow} rel="noopener noreferrer" target="_blank">
              {announcement.ctaLabel}
              <ArrowRight aria-hidden className="size-4" />
            </a>
          ) : (
            <Link className={ctaClass} href={announcement.ctaUrl} onClick={onFollow}>
              {announcement.ctaLabel}
              <ArrowRight aria-hidden className="size-4" />
            </Link>
          )}
        </div>
      </RadixDialog.Content>
    </RadixDialog.Portal>
  );
}
