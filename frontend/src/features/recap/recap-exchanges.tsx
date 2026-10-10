"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { HeartHandshake, UserPlus } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { sendFriendRequest } from "@/features/community/community-friends-api";
import { MemberAvatar } from "@/features/community/member-avatar";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchRecapExchanges, type ExchangePlayer, type SlotExchange, type Unblock } from "./recap-exchanges-api";

/** « Alice », « Alice et Bob », « Alice, Bob et Carol »; the slot's name when no player has a public profile. */
export function playersLabel(players: ExchangePlayer[], slotName: string): string {
  const names = players.map((player) => player.displayName ?? player.slug);
  if (names.length === 0) return slotName;
  if (names.length === 1) return names[0];
  return `${names.slice(0, -1).join(", ")} et ${names[names.length - 1]}`;
}

function plural(count: number, word: string): string {
  return `${count} ${word}${count > 1 ? "s" : ""}`;
}

/** « 3 envoyés (1 de progression), 2 reçus ». */
export function exchangeLine(exchange: SlotExchange): string {
  const sent = `${plural(exchange.sent, "envoyé")}${exchange.sentProgression > 0 ? ` (${exchange.sentProgression} de progression)` : ""}`;
  const received = `${plural(exchange.received, "reçu")}${exchange.receivedProgression > 0 ? ` (${exchange.receivedProgression} de progression)` : ""}`;
  return `${sent}, ${received}`;
}

/** « Alice t'a envoyé Grappin qui t'a sorti du BK ». */
export function unblockLine(unblock: Unblock): string {
  const who = playersLabel(unblock.senders, unblock.senderName);
  const verb = unblock.senders.length > 1 ? "t'ont envoyé" : "t'a envoyé";
  return `${who} ${verb} ${unblock.itemName ?? "un objet"} qui t'a sorti du BK`;
}

/**
 * « Entre nous » (story 43.10): in the recap, for a player of the session only, what they exchanged with each other
 * slot (friends first), and the BKs another player got them out of. Loaded client-side: the recap page is public.
 */
export function RecapExchanges({ sessionId }: { sessionId: string }) {
  const { user } = useAuth();
  const [requested, setRequested] = useState<ReadonlySet<string>>(new Set());
  const { data } = useQuery({
    queryKey: ["recap-exchanges", sessionId],
    queryFn: () => fetchRecapExchanges(sessionId),
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (user === null || !data || (data.exchanges.length === 0 && data.unblocks.length === 0)) return null;

  async function addFriend(player: ExchangePlayer) {
    if ((await sendFriendRequest(player.slug)) !== null) {
      setRequested((current) => new Set(current).add(player.userId));
    }
  }

  return (
    <section aria-labelledby="recap-exchanges" className="grid gap-4">
      <h2 className="flex items-center gap-2 font-heading text-xl font-bold text-foreground" id="recap-exchanges">
        <HeartHandshake aria-hidden className="size-5 text-accent-text" />
        Entre nous
      </h2>
      {data.unblocks.length > 0 ? (
        <ul className="grid gap-2" role="list">
          {data.unblocks.map((unblock) => (
            <li className="rounded-lg border border-success/40 bg-success/10 px-4 py-2 text-sm text-foreground" key={`${unblock.slotName}-${unblock.at}`}>
              {unblockLine(unblock)}
            </li>
          ))}
        </ul>
      ) : null}
      <ul className="grid gap-2 sm:grid-cols-2" role="list">
        {data.exchanges.map((exchange) => (
          <li className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2" key={exchange.slotName}>
            <span className="flex -space-x-2">
              {exchange.players.slice(0, 3).map((player) => (
                <MemberAvatar
                  avatarUrl={player.avatarUrl}
                  className="ring-2 ring-background"
                  framing={player.avatarFraming}
                  hoverVideo={false}
                  key={player.userId}
                  name={player.displayName ?? player.slug}
                  size={32}
                />
              ))}
            </span>
            <span className="grid min-w-0 flex-1 gap-0.5">
              <span className="truncate text-sm font-medium text-foreground">
                {exchange.players.length === 1 ? (
                  <Link className="hover:text-accent-text" href={`/joueurs/${exchange.players[0].slug}`}>
                    {playersLabel(exchange.players, exchange.slotName)}
                  </Link>
                ) : (
                  playersLabel(exchange.players, exchange.slotName)
                )}
              </span>
              <span className="truncate text-xs text-muted-foreground">{exchangeLine(exchange)}</span>
            </span>
            {exchange.players
              .filter((player) => !player.isFriend && player.canAdd !== false && !requested.has(player.userId))
              .slice(0, 1)
              .map((player) => (
                <button
                  aria-label={`Ajouter ${player.displayName ?? player.slug} en ami`}
                  className="inline-flex shrink-0 items-center gap-1 rounded-full border border-border px-2.5 py-1 text-xs font-semibold text-foreground hover:border-accent"
                  key={player.userId}
                  onClick={() => {
                    void addFriend(player);
                  }}
                  type="button"
                >
                  <UserPlus aria-hidden className="size-3.5" />
                  Ajouter
                </button>
              ))}
          </li>
        ))}
      </ul>
    </section>
  );
}
