"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { SlidersHorizontal } from "lucide-react";

import { Switch } from "@/components/switch";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  chooseNotificationChannel,
  chooseNotificationDiscord,
  fetchNotificationPreferences,
  type NotificationChannel,
  type NotificationPreference,
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
  run_started: { label: "Partie lancée", hint: "Le serveur d'une partie où tu joues est prêt." },
  run_joined: { label: "Arrivées dans mes parties", hint: "Quelqu'un rejoint une partie que tu as créée." },
  run_games_chosen: { label: "Jeux choisis dans mes parties", hint: "Un joueur de ta partie a choisi ou changé ses jeux." },
  run_all_ready: { label: "Tout le monde est prêt", hint: "Tous les joueurs de ta partie se sont dits prêts : tu peux lancer." },
  run_recap_ready: { label: "Récap prêt", hint: "Une partie où tu as joué est terminée et son récap est prêt." },
};

/**
 * Story 40.7: the stored channel read as two switches. The bell is the base - off means nothing anywhere, back on means
 * the bell alone; the push only adds to it.
 */
export function channelAfter(current: NotificationChannel, column: "bell" | "push", on: boolean): NotificationChannel {
  if (column === "bell") return on ? "bell" : "none";
  if (current === "none") return current;
  return on ? "bell_push" : "bell";
}

/**
 * Story 43.11b, 40.7: per type of notification, a switch for the bell, the push (which still needs the device turned
 * on above) and, once the member's Discord is linked, their Discord direct messages (40.6).
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

  const withDiscord = data.some((preference) => preference.discord !== null);

  async function save(next: Promise<NotificationPreference[] | null>) {
    const settings = await next;
    setFailed(settings === null);
    if (settings !== null) queryClient.setQueryData(NOTIFICATION_PREFERENCES_KEY, settings);
  }

  const headClass = "px-2 pb-2 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground";

  return (
    <section aria-labelledby="notification-preferences" className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground" id="notification-preferences">
        <SlidersHorizontal aria-hidden className="size-5 text-accent-text" />
        Ce qui me prévient
      </h2>
      <table className="w-full border-collapse">
        <thead>
          <tr className="border-b border-border">
            <th className="pb-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground" scope="col">
              Notification
            </th>
            <th className={headClass} scope="col">
              Cloche
            </th>
            <th className={headClass} scope="col">
              Push
            </th>
            {withDiscord ? (
              <th className={headClass} scope="col">
                Discord
              </th>
            ) : null}
          </tr>
        </thead>
        <tbody>
          {data.map((preference) => {
            const labels = TYPE_LABELS[preference.type] ?? { label: preference.type, hint: "" };
            const bell = preference.channel !== "none";
            return (
              <tr className="border-b border-border/60 last:border-b-0" key={preference.type}>
                <th className="py-3 pr-2 text-left align-middle font-normal" scope="row">
                  <span className="block text-sm font-medium text-foreground">{labels.label}</span>
                  {labels.hint !== "" ? <span className="block text-xs text-muted-foreground">{labels.hint}</span> : null}
                </th>
                <td className="px-2 py-3 text-center align-middle">
                  <Switch
                    ariaLabel={`${labels.label} : cloche`}
                    checked={bell}
                    onChange={(on) => {
                      void save(chooseNotificationChannel(preference.type, channelAfter(preference.channel, "bell", on)));
                    }}
                  />
                </td>
                <td className="px-2 py-3 text-center align-middle">
                  <Switch
                    ariaLabel={`${labels.label} : push`}
                    checked={preference.channel === "bell_push"}
                    disabled={!bell}
                    onChange={(on) => {
                      void save(chooseNotificationChannel(preference.type, channelAfter(preference.channel, "push", on)));
                    }}
                  />
                </td>
                {withDiscord ? (
                  <td className="px-2 py-3 text-center align-middle">
                    {preference.discord !== null ? (
                      <Switch
                        ariaLabel={`${labels.label} : Discord`}
                        checked={bell && preference.discord}
                        disabled={!bell}
                        onChange={(on) => {
                          void save(chooseNotificationDiscord(preference.type, on));
                        }}
                      />
                    ) : null}
                  </td>
                ) : null}
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="text-xs text-muted-foreground">
        Couper la cloche coupe tout pour ce type. Le push demande aussi d&apos;activer cet appareil plus haut.
        {withDiscord ? " Discord : un message privé du bot." : ""}
      </p>
      {failed ? (
        <p className="text-sm text-danger" role="alert">
          Réglage non enregistré, réessaie.
        </p>
      ) : null}
    </section>
  );
}
