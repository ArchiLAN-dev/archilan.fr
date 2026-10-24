"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, BellRing } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { FOLLOWED_GAMES_KEY, fetchGameFollow, gameFollowKey, setGameFollow } from "./game-follow-api";

/**
 * Follow a game from its page (story 38.22): the member is told in the notification center each time
 * the site puts a new apworld in place for it. Signed out there is nothing to offer.
 */
export function GameFollowButton({ gameId }: { gameId: string }) {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const queryKey = gameFollowKey(gameId);

  const { data: following } = useQuery({
    enabled: user !== null,
    queryFn: () => fetchGameFollow(gameId),
    queryKey,
    staleTime: 5 * 60 * 1000,
  });

  const mutation = useMutation({
    mutationFn: (follow: boolean) => setGameFollow(gameId, follow),
    onSuccess: (settled) => {
      if (settled !== null) queryClient.setQueryData(queryKey, settled);
      // Story 38.23: the account's list of followed games reads the change on its next view.
      void queryClient.invalidateQueries({ queryKey: FOLLOWED_GAMES_KEY });
    },
  });

  if (user === null || following === null || following === undefined) {
    return null;
  }

  return (
    <button
      aria-pressed={following}
      className={
        following
          ? "inline-flex items-center gap-1.5 rounded border border-accent/50 bg-accent/10 px-2.5 py-1 text-sm font-semibold text-accent-text transition-colors hover:border-accent disabled:opacity-50"
          : "inline-flex items-center gap-1.5 rounded border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
      }
      disabled={mutation.isPending}
      onClick={() => { mutation.mutate(!following); }}
      title={following ? "Ne plus être prévenu des nouveaux apworlds de ce jeu" : "Être prévenu quand un nouvel apworld est mis en place pour ce jeu"}
      type="button"
    >
      {following ? <BellRing aria-hidden="true" className="size-4" /> : <Bell aria-hidden="true" className="size-4" />}
      {following ? "Suivi" : "Suivre ce jeu"}
    </button>
  );
}
