"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { BellRing } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { FOLLOWED_GAMES_KEY, fetchFollowedGames, gameFollowKey, setGameFollow, type FollowedGame } from "./game-follow-api";

/**
 * Story 38.23: the games the member follows, each with a way to stop. Following starts from a game's page; an empty
 * list says so.
 */
export function FollowedGamesCard() {
  const queryClient = useQueryClient();
  const [failed, setFailed] = useState(false);
  const { data } = useQuery({
    queryKey: FOLLOWED_GAMES_KEY,
    queryFn: fetchFollowedGames,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data) return null;

  async function unfollow(gameId: string) {
    const following = await setGameFollow(gameId, false);
    setFailed(following === null);
    if (following === false) {
      queryClient.setQueryData<FollowedGame[]>(FOLLOWED_GAMES_KEY, (games) => games?.filter((game) => game.gameId !== gameId));
      queryClient.setQueryData(gameFollowKey(gameId), false);
    }
  }

  return (
    <section aria-labelledby="followed-games" className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground" id="followed-games">
        <BellRing aria-hidden className="size-5 text-accent-text" />
        Jeux que je suis
      </h2>
      {data.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Tu ne suis aucun jeu. Sur la page d&apos;un jeu, « Suivre ce jeu » te prévient de ses nouveaux apworlds.
        </p>
      ) : (
        <ul className="grid gap-2">
          {data.map((game) => (
            <li className="flex items-center justify-between gap-3 border-b border-border/60 pb-2 last:border-b-0" key={game.gameId}>
              <Link className="text-sm font-medium text-foreground hover:text-accent-text" href={`/jeux/${game.gameSlug}`}>
                {game.gameName}
              </Link>
              <button
                className="text-xs text-muted-foreground hover:text-danger"
                onClick={() => void unfollow(game.gameId)}
                type="button"
              >
                Ne plus suivre
              </button>
            </li>
          ))}
        </ul>
      )}
      {failed ? (
        <p className="text-sm text-danger" role="alert">
          Impossible d&apos;arrêter le suivi. Réessaie.
        </p>
      ) : null}
    </section>
  );
}
