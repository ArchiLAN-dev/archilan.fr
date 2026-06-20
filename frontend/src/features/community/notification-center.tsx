"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, Loader2 } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { hasStringProp } from "@/lib/type-guards";
import {
  fetchNotificationStreamToken,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  type NotificationItem,
} from "./notifications-api";

const QUERY_KEY = ["community-notifications"] as const;
const STALE_TIME = 20_000;
const POLL_INTERVAL = 60_000;

/** The bell + dropdown in the header for an authenticated user. Polls, and live-pushes via Mercure. */
export function NotificationCenter() {
  const { user } = useAuth();
  const userId = user?.id ?? null;
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const { data } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => fetchNotifications(),
    enabled: userId !== null,
    staleTime: STALE_TIME,
    refetchInterval: POLL_INTERVAL,
  });

  // Live push: subscribe to the user's private Mercure topic; the 60 s poll is the fallback.
  useEffect(() => {
    if (userId === null) return;
    let cancelled = false;
    let es: EventSource | null = null;
    void (async () => {
      const stream = await fetchNotificationStreamToken();
      if (cancelled || stream === null || stream.hubUrl === "") return;
      const url = new URL(stream.hubUrl);
      url.searchParams.set("topic", stream.topic);
      url.searchParams.set("authorization", stream.token);
      es = new EventSource(url.toString());
      es.onmessage = () => {
        void queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      };
      // Stop the browser's automatic reconnect loop on a rejected/dropped private subscription
      // (e.g. an expired token); the 60 s poll keeps the center fresh as the fallback.
      es.onerror = () => {
        es?.close();
        es = null;
      };
    })();
    return () => {
      cancelled = true;
      es?.close();
    };
  }, [userId, queryClient]);

  // Close the dropdown on an outside click.
  useEffect(() => {
    if (!open) return;
    function onClick(event: MouseEvent): void {
      if (containerRef.current && event.target instanceof Node && !containerRef.current.contains(event.target)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  if (userId === null) return null;

  const items = data?.items ?? [];
  const unread = data?.unreadCount ?? 0;

  async function handleItemClick(item: NotificationItem): Promise<void> {
    setOpen(false);
    if (!item.read) {
      await markNotificationRead(item.id);
      await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    }
  }

  async function handleMarkAll(): Promise<void> {
    await markAllNotificationsRead();
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        aria-expanded={open}
        aria-label={unread > 0 ? `Notifications (${unread} non lues)` : "Notifications"}
        className="relative inline-flex size-11 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
        onClick={() => setOpen((v) => !v)}
        type="button"
      >
        <Bell aria-hidden className="size-5" />
        {unread > 0 ? (
          <span className="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1 text-[11px] font-bold text-white">
            {unread > 9 ? "9+" : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-border bg-surface shadow-xl">
          <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
            <span className="font-heading text-sm font-semibold text-foreground">Notifications</span>
            {unread > 0 ? (
              <button
                className="text-xs font-semibold text-accent-text hover:underline"
                onClick={() => void handleMarkAll()}
                type="button"
              >
                Tout marquer comme lu
              </button>
            ) : null}
          </div>

          {data === undefined ? (
            <p className="flex items-center gap-2 px-4 py-6 text-sm text-muted-foreground">
              <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
            </p>
          ) : items.length === 0 ? (
            <p className="px-4 py-6 text-sm text-muted-foreground">Aucune notification.</p>
          ) : (
            <ul className="max-h-96 overflow-y-auto" role="list">
              {items.map((item) => (
                <li key={item.id}>
                  <NotificationRow item={item} onSelect={() => void handleItemClick(item)} />
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}

function NotificationRow({ item, onSelect }: { item: NotificationItem; onSelect: () => void }) {
  const href = hrefFor(item);
  const content = (
    <span className="flex items-start gap-2.5">
      {item.read ? null : <span aria-hidden className="mt-1.5 size-2 shrink-0 rounded-full bg-accent" />}
      <span className={`min-w-0 flex-1 ${item.read ? "pl-[18px]" : ""}`}>
        <span className="block text-sm text-foreground">{messageFor(item)}</span>
        <time className="text-xs text-muted-foreground" dateTime={item.createdAt}>
          {relativeTime(item.createdAt)}
        </time>
      </span>
    </span>
  );

  return (
    <Link
      className={`block px-4 py-3 transition-colors hover:bg-accent/10 ${item.read ? "" : "bg-accent/5"}`}
      href={href}
      onClick={onSelect}
    >
      {content}
    </Link>
  );
}

function actorName(item: NotificationItem): string {
  return item.actor?.displayName ?? item.actor?.slug ?? "Quelqu'un";
}

function messageFor(item: NotificationItem): string {
  switch (item.type) {
    case "friend_request_received":
      return `${actorName(item)} t'a envoyé une demande d'ami`;
    case "friend_request_accepted":
      return `${actorName(item)} a accepté ta demande d'ami`;
    case "comment_received":
      return `${actorName(item)} a commenté ton profil`;
    case "kudos_received":
      return hasStringProp(item.data, "targetType") && item.data.targetType === "achievement"
        ? `${actorName(item)} a aimé un de tes succès`
        : `${actorName(item)} a aimé une de tes runs`;
    case "achievement_unlocked":
      return "Nouveau succès débloqué \u{1F3C6}";
    case "account_flagged":
      return hasStringProp(item.data, "displayName") && item.data.displayName !== ""
        ? `Compte à examiner : ${item.data.displayName}`
        : "Un compte a atteint le seuil de modération";
    default:
      return "Nouvelle notification";
  }
}

function hrefFor(item: NotificationItem): string {
  if (item.type === "account_flagged") {
    return "/admin/moderation";
  }
  if (item.actor !== null && item.type !== "achievement_unlocked") {
    return `/joueurs/${item.actor.slug}`;
  }
  return "/compte";
}

function relativeTime(iso: string): string {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diff = Date.now() - ts;
  if (diff < 60_000) return "à l'instant";
  if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
  if (diff < 86_400_000) return `il y a ${Math.floor(diff / 3_600_000)} h`;
  return `il y a ${Math.floor(diff / 86_400_000)} j`;
}
