"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { fetchGameListIds, setGameInList, type GameListKind } from "./game-lists-api";

/**
 * One of the player's ArchiLAN-side game lists (story 28.13), shared by every surface that needs
 * it: the catalog filter, the run's game selection, and the badge on a game page.
 *
 * The kind is a parameter rather than a hook per list: each list is its own query key, so two of
 * them never invalidate each other, and adding one costs nothing here.
 *
 * Signed out, the list is empty and marking is unavailable - unlike the Steam coupling, which works
 * anonymously through localStorage, these belong to an account, which is precisely what makes them
 * survive a change of browser.
 */
export function useGameList(kind: GameListKind): {
  gameIds: Set<string>;
  canMark: boolean;
  settled: boolean;
  isInList: (gameId: string) => boolean;
  toggle: (gameId: string, inList: boolean) => void;
  pending: boolean;
} {
  const { user, loading } = useAuth();
  const queryClient = useQueryClient();
  const signedIn = user !== null;
  const queryKey = ["game-lists", kind] as const;

  const { data, isFetched } = useQuery({
    enabled: signedIn,
    queryFn: () => fetchGameListIds(kind),
    queryKey,
    staleTime: 5 * 60 * 1000,
  });

  const gameIds = new Set(signedIn ? (data ?? []) : []);

  const mutation = useMutation({
    mutationFn: ({ gameId, inList }: { gameId: string; inList: boolean }) =>
      setGameInList(kind, gameId, inList),
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  });

  return {
    canMark: signedIn,
    gameIds,
    isInList: (gameId: string) => gameIds.has(gameId),
    pending: mutation.isPending,
    // An empty list means "nothing on it" only once auth has resolved and, when signed in, the
    // query has answered. Before that it is indistinguishable from "not loaded yet", and a caller
    // acting on it would wipe a filter the URL had just restored (the trap story 28.12 hit).
    settled: !loading && (!signedIn || isFetched),
    toggle: (gameId: string, inList: boolean) => mutation.mutate({ gameId, inList }),
  };
}
