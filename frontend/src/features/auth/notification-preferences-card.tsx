"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { SlidersHorizontal } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  chooseNotificationChannel,
  fetchNotificationPreferences,
  NOTIFICATION_CHANNELS,
  type NotificationChannel,
} from "./notification-preferences-api";

export const NOTIFICATION_PREFERENCES_KEY = ["notification-preferences"] as const;

const TYPE_LABELS: Record<string, { label: string; hint: string }> = {
  friend_activity: {
    label: "Activité de mes amis favoris",
    hint: "Un favori s'inscrit à un événement, lance une partie ou atteint son objectif.",
  },
  run_invitation: { label: "Invitations dans une run", hint: "Un ami t'invite dans sa partie." },
  slot_unblocked: { label: "Sortie de BK", hint: "Ton slot a de nouveau des checks accessibles." },
  run_nudge: { label: "Relances de mes co-joueurs", hint: "Un co-joueur attend ta prochaine session dans une partie." },
};

export const CHANNEL_LABELS: Record<NotificationChannel, string> = {
  bell_push: "Cloche + push",
  bell: "Cloche seulement",
  none: "Rien",
};

/**
 * Story 43.11b: per type of notification, the bell and this member's devices, the bell only, or nothing. The push
 * still needs the device turned on above.
 */
export function NotificationPreferencesCard() {
  const queryClient = useQueryClient();
  const [failed, setFailed] = useState(false);
  const { data } = useQuery({
    queryKey: NOTIFICATION_PREFERENCES_KEY,
    queryFn: fetchNotificationPreferences,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data) return null;

  async function choose(type: string, channel: NotificationChannel) {
    const next = await chooseNotificationChannel(type, channel);
    setFailed(next === null);
    if (next !== null) queryClient.setQueryData(NOTIFICATION_PREFERENCES_KEY, next);
  }

  return (
    <section aria-labelledby="notification-preferences" className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground" id="notification-preferences">
        <SlidersHorizontal aria-hidden className="size-5 text-accent-text" />
        Ce qui me prévient
      </h2>
      <ul className="grid gap-4" role="list">
        {data.map((preference) => {
          const labels = TYPE_LABELS[preference.type] ?? { label: preference.type, hint: "" };
          return (
            <li className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-center" key={preference.type}>
              <span className="grid gap-0.5">
                <span className="text-sm font-medium text-foreground">{labels.label}</span>
                {labels.hint !== "" ? <span className="text-xs text-muted-foreground">{labels.hint}</span> : null}
              </span>
              <span aria-label={labels.label} className="inline-flex flex-wrap gap-1" role="radiogroup">
                {NOTIFICATION_CHANNELS.map((channel) => (
                  <button
                    aria-checked={preference.channel === channel}
                    className={`min-h-9 cursor-pointer rounded-full border px-3 text-xs font-semibold transition-colors ${
                      preference.channel === channel
                        ? "border-accent bg-accent/15 text-accent-text"
                        : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
                    }`}
                    key={channel}
                    onClick={() => {
                      void choose(preference.type, channel);
                    }}
                    role="radio"
                    type="button"
                  >
                    {CHANNEL_LABELS[channel]}
                  </button>
                ))}
              </span>
            </li>
          );
        })}
      </ul>
      {failed ? (
        <p className="text-sm text-danger" role="alert">
          Réglage non enregistré, réessaie.
        </p>
      ) : null}
    </section>
  );
}
