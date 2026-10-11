"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { UserPlus, X } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { FriendIdentity } from "./friend-identity";
import {
  dismissFriendSuggestion,
  fetchFriendSuggestions,
  sendFriendRequest,
  type FriendSuggestion,
} from "./community-friends-api";

type Props = {
  /** The heading of the block. */
  title: string;
  /** With a session (a recap), only the members played with in it. */
  sessionId?: string;
  limit?: number;
};

/**
 * « Tu as joué avec » (story 43.2): the members played with, to add in one click or wave away for good. Only
 * for a signed-in member, and nothing at all when there is nobody to suggest.
 */
export function FriendSuggestions({ title, sessionId, limit }: Props) {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const queryKey = ["community-friend-suggestions", sessionId ?? null, limit ?? null];
  const { data } = useQuery({
    queryKey,
    queryFn: () => fetchFriendSuggestions({ sessionId, limit }),
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (user === null || !data || data.length === 0) {
    return null;
  }

  async function act(suggestion: FriendSuggestion, action: "add" | "dismiss") {
    setBusy(suggestion.slug);
    const ok = action === "add" ? (await sendFriendRequest(suggestion.slug)) !== null : await dismissFriendSuggestion(suggestion.slug);
    setBusy(null);
    if (ok) {
      await queryClient.invalidateQueries({ queryKey: ["community-friend-suggestions"] });
      await queryClient.invalidateQueries({ queryKey: ["community-friends"] });
    }
  }

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">{title}</h2>
      <ul className="grid gap-2 sm:grid-cols-2" role="list">
        {data.map((suggestion) => (
          <li className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2" key={suggestion.userId}>
            <span className="grid min-w-0 flex-1 gap-0.5">
              <FriendIdentity card={suggestion} link />
              <span className="truncate pl-12 text-xs text-muted-foreground">{togetherLine(suggestion)}</span>
            </span>
            <div className="flex shrink-0 items-center gap-1">
              <button
                aria-label={`Ajouter ${suggestion.displayName ?? suggestion.slug} en ami`}
                className="inline-flex items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
                disabled={busy !== null}
                onClick={() => {
                  void act(suggestion, "add");
                }}
                type="button"
              >
                <UserPlus aria-hidden className="size-3.5" />
                Ajouter
              </button>
              <button
                aria-label={`Ignorer ${suggestion.displayName ?? suggestion.slug}`}
                className="inline-flex size-8 items-center justify-center rounded-full border border-border text-muted-foreground hover:text-foreground disabled:opacity-50"
                disabled={busy !== null}
                onClick={() => {
                  void act(suggestion, "dismiss");
                }}
                title="Ne plus me le proposer"
                type="button"
              >
                <X aria-hidden className="size-4" />
              </button>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function togetherLine(suggestion: FriendSuggestion): string {
  const count = `${suggestion.sessionsTogether} ${suggestion.sessionsTogether > 1 ? "parties" : "partie"} ensemble`;
  return suggestion.lastTitle !== null ? `${count} · ${suggestion.lastTitle}` : count;
}
