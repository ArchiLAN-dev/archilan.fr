"use client";

import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, Loader2 } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";
import {
  fetchNotificationStreamToken,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  type NotificationItem,
} from "./notifications-api";
import { sectionsOf, timeLabel } from "./notification-content";
import { NotificationRow } from "./notification-row";

const QUERY_KEY = ["community-notifications"] as const;
const STALE_TIME = 20_000;
const POLL_INTERVAL = 60_000;

/** The bell + dropdown in the header for an authenticated user. Polls, and live-pushes via Mercure. */
export function NotificationCenter() {
  const { user } = useAuth();
  const userId = user?.id ?? null;
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  // The clock the periods and times are read against: taken when the bell opens, never during a render.
  const [openedAt, setOpenedAt] = useState<Date | null>(null);
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
  const now = openedAt ?? new Date(0);

  async function markRead(row: NotificationItem[]): Promise<void> {
    const unreadIds = row.filter((item) => !item.read).map((item) => item.id);
    if (unreadIds.length === 0) return;
    await Promise.all(unreadIds.map((id) => markNotificationRead(id)));
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
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
        onClick={() => {
          setOpenedAt(new Date());
          setOpen((v) => !v);
        }}
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
        <div className="absolute right-0 z-50 mt-2 w-[min(25rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-surface shadow-xl">
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
            <div className="max-h-[32rem] overflow-y-auto">
              {/* Story 30.48: by period, the kudos of a day on one row. */}
              {sectionsOf(items, now).map((section) => (
                <section aria-label={section.label} key={section.label}>
                  <h3 className="border-t border-border px-4 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground first:border-t-0">
                    {section.label}
                  </h3>
                  <ul role="list">
                    {section.rows.map((row) => {
                      const first = row.items[0];
                      if (first === undefined) return null;
                      return (
                        <li key={row.key}>
                          <NotificationRow
                            fallback={messageFor(first)}
                            href={hrefFor(first)}
                            items={row.items}
                            onRead={() => void markRead(row.items)}
                            onSelect={() => {
                              setOpen(false);
                              void markRead(row.items);
                            }}
                            timeLabel={timeLabel(first.createdAt, now)}
                          />
                        </li>
                      );
                    })}
                  </ul>
                </section>
              ))}
            </div>
          )}
        </div>
      ) : null}
    </div>
  );
}

function actorName(item: NotificationItem): string {
  return item.actor?.displayName ?? item.actor?.slug ?? "Quelqu'un";
}

export function messageFor(item: NotificationItem): string {
  switch (item.type) {
    case "friend_request_received":
      return `${actorName(item)} t'a envoyé une demande d'ami`;
    case "friend_request_accepted":
      return `${actorName(item)} a accepté ta demande d'ami`;
    case "run_invitation":
      // Story 43.1: answered on « Mes parties ».
      return hasStringProp(item.data, "runTitle") && item.data.runTitle !== ""
        ? `${actorName(item)} t'invite dans « ${item.data.runTitle} »`
        : `${actorName(item)} t'invite dans sa partie`;
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
    case "moderation_reply":
      return "La modération t'a répondu";
    case "pelles_adjusted": {
      // Story 41.1: an admin credited or debited the member's pelles; the reason is theirs to read.
      const amount = hasNumberProp(item.data, "amount") ? item.data.amount : 0;
      // Story 41.2: event pelles name their event.
      const event = hasStringProp(item.data, "eventTitle") && item.data.eventTitle !== "" ? ` pour « ${item.data.eventTitle} »` : "";
      const what = `${Math.abs(amount)} ${Math.abs(amount) > 1 ? "pelles" : "pelle"}${event}`;
      const reason = hasStringProp(item.data, "reason") && item.data.reason !== "" ? ` : ${item.data.reason}` : "";
      return amount < 0 ? `L'équipe t'a retiré ${what}${reason}` : `Tu as reçu ${what}${reason}`;
    }
    case "cosmetic_unlocked": {
      // Story 41.28: a cosmetic won through an achievement or a quest.
      const label = hasStringProp(item.data, "label") ? item.data.label : "Un cosmétique";
      const source = hasStringProp(item.data, "source") ? item.data.source : "";
      const kind = source === "quest" ? "quête" : source === "collection" ? "collection" : "succès";
      const from = hasStringProp(item.data, "sourceLabel") ? ` (${kind} « ${item.data.sourceLabel} »)` : "";
      return `Débloqué : ${label}${from}`;
    }
    case "collection_completed": {
      // Story 30.52: a collection of achievements completed.
      const name = hasStringProp(item.data, "name") && item.data.name !== "" ? ` : ${item.data.name}` : "";
      return `Collection complète${name} \u{1F3C6}`;
    }
    case "quests_renewed": {
      // Story 41.17: the quests of the new week are out.
      const count = hasNumberProp(item.data, "count") ? item.data.count : 0;
      const max = hasNumberProp(item.data, "maxPelles") ? item.data.maxPelles : 0;
      const quests = count > 0 ? ` : ${count} ${count > 1 ? "quêtes" : "quête"}` : "";
      const pelles = max > 0 ? `, jusqu'à ${max} pelles` : "";
      return `Nouvelles quêtes de la semaine${quests}${pelles}`;
    }
    case "moderation_warning":
      return hasStringProp(item.data, "reason") && item.data.reason !== ""
        ? `Avertissement de la modération : ${item.data.reason}`
        : "La modération t'a envoyé un avertissement";
    case "slot_unblocked": {
      // Story 40.1: a slot of a private run left a real BK.
      const runTitle = hasStringProp(item.data, "runTitle") && item.data.runTitle !== "" ? item.data.runTitle : null;
      if (runTitle === null) {
        return "Tu n'es plus bloqué dans ta partie";
      }
      const slot = hasStringProp(item.data, "slotName") && item.data.slotName !== "" ? ` (${item.data.slotName})` : "";
      const count = hasNumberProp(item.data, "reachableNow") ? item.data.reachableNow : null;
      const checks = count === null ? "" : ` : ${count} ${count > 1 ? "checks accessibles" : "check accessible"}`;
      return `Tu n'es plus bloqué dans « ${runTitle} »${slot}${checks}`;
    }
    case "generation_failed": {
      const runTitle =
        hasStringProp(item.data, "runTitle") && item.data.runTitle !== "" ? ` « ${item.data.runTitle} »` : "";
      if (
        hasStringProp(item.data, "role") &&
        item.data.role === "player" &&
        hasStringProp(item.data, "slotName") &&
        item.data.slotName !== ""
      ) {
        return `Ta config (slot « ${item.data.slotName} ») a fait échouer la génération de la partie${runTitle}`;
      }
      return `La génération de la partie${runTitle} a échoué`;
    }
    case "apworld_incident_opened": {
      // Stories 38.4 and 38.6: say which problem it is - an update that could not go through is not a
      // broken apworld, and a real generation failing is not the import test.
      const incidentType = hasStringProp(item.data, "incidentType") ? item.data.incidentType : "";
      const problem =
        incidentType === "update_rejected"
          ? "Mise à jour rejetée"
          : incidentType === "update_ambiguous"
            ? "Mise à jour à arbitrer"
            : incidentType === "default_yaml_failure"
              ? "Échec avec le YAML par défaut"
              : incidentType === "image_regression"
                ? "Régression d'image"
                : "Apworld en échec";
      return hasStringProp(item.data, "gameName") && item.data.gameName !== ""
        ? `${problem} : ${item.data.gameName}`
        : "Un apworld est en échec";
    }
    case "slot_yaml_needs_review": {
      // Story 38.7: the game switched apworld and the player's own YAML no longer holds.
      const game = hasStringProp(item.data, "gameName") && item.data.gameName !== "" ? item.data.gameName : "Un jeu";
      const where =
        hasStringProp(item.data, "runTitle") && item.data.runTitle !== ""
          ? ` (« ${item.data.runTitle} »)`
          : hasStringProp(item.data, "eventTitle") && item.data.eventTitle !== ""
            ? ` (« ${item.data.eventTitle} »)`
            : "";
      return `${game} a changé de version : ton YAML est à revoir${where}`;
    }
    default:
      return "Nouvelle notification";
  }
}

export function hrefFor(item: NotificationItem): string {
  if (item.type === "account_flagged") {
    return "/admin/moderation/signalements";
  }
  if (item.type === "pelles_adjusted" || item.type === "quests_renewed") {
    return "/compte/portefeuille";
  }
  if (item.type === "cosmetic_unlocked") {
    // Story 41.28: where the member puts it on.
    return "/compte/profil";
  }
  if (item.type === "apworld_incident_opened") {
    // The apworld health page (story 38.3): the incident, who holds it, and the actions.
    return "/admin/sante-apworlds";
  }
  if (item.type === "generation_failed") {
    return hasStringProp(item.data, "runId") && item.data.runId !== "" ? `/runs/${item.data.runId}` : "/compte";
  }
  if (item.type === "run_invitation") {
    return "/compte/parties";
  }
  if (item.type === "slot_unblocked") {
    if (!hasStringProp(item.data, "runId") || item.data.runId === "") {
      return "/compte/parties";
    }
    // Story 40.5: the progression of the unblocked slot; a notice from before it only knows the run.
    return hasStringProp(item.data, "slotIndex") && item.data.slotIndex !== ""
      ? `/runs/${item.data.runId}/progression/${item.data.slotIndex}`
      : `/runs/${item.data.runId}`;
  }
  if (item.type === "slot_yaml_needs_review") {
    // Where the slot is marked "à revoir" (story 38.7): the run game selection, or the registration
    // recap that lists the event slots with their YAML.
    if (hasStringProp(item.data, "runId") && item.data.runId !== "") {
      return `/runs/${item.data.runId}/jeux`;
    }
    if (
      hasStringProp(item.data, "eventId") &&
      item.data.eventId !== "" &&
      hasStringProp(item.data, "registrationId") &&
      item.data.registrationId !== ""
    ) {
      return `/evenements/${item.data.eventId}/inscription/${item.data.registrationId}/recap`;
    }
    return "/compte";
  }
  if (item.actor !== null && item.type !== "achievement_unlocked") {
    return `/joueurs/${item.actor.slug}`;
  }
  return "/compte";
}
