"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Check, X } from "lucide-react";

import { CommunityLoadingSkeleton } from "./community-loading-skeleton";
import {
  acceptFriendship,
  declineFriendship,
  fetchFriends,
  type FriendCard,
  type FriendsData,
  type IncomingRequest,
} from "./community-friends-api";

export function CommunityFriendsPanel() {
  const [data, setData] = useState<FriendsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const reload = useCallback(async () => {
    setData(await fetchFriends());
  }, []);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const d = await fetchFriends();
      if (!cancelled) {
        setData(d);
        setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  async function respond(id: string, accept: boolean) {
    setBusy(true);
    const ok = accept ? await acceptFriendship(id) : await declineFriendship(id);
    setBusy(false);
    if (ok) await reload();
  }

  if (loading) {
    return <CommunityLoadingSkeleton rows={3} />;
  }

  if (data === null) {
    return <p className="text-sm text-muted-foreground">Impossible de charger tes amis.</p>;
  }

  return (
    <div className="grid gap-8">
      {data.incoming.length > 0 ? (
        <section className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground">
            Demandes reçues <span className="text-sm font-normal text-muted-foreground">({data.incoming.length})</span>
          </h2>
          <ul className="grid gap-2" role="list">
            {data.incoming.map((req: IncomingRequest) => (
              <li className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2" key={req.friendshipId}>
                <FriendIdentity card={req} />
                <div className="flex shrink-0 items-center gap-1">
                  <button
                    aria-label="Accepter"
                    className="inline-flex size-8 items-center justify-center rounded-full bg-accent text-white hover:bg-accent-hover disabled:opacity-50"
                    disabled={busy}
                    onClick={() => { void respond(req.friendshipId, true); }}
                    type="button"
                  >
                    <Check aria-hidden className="size-4" />
                  </button>
                  <button
                    aria-label="Refuser"
                    className="inline-flex size-8 items-center justify-center rounded-full border border-border text-muted-foreground hover:text-foreground disabled:opacity-50"
                    disabled={busy}
                    onClick={() => { void respond(req.friendshipId, false); }}
                    type="button"
                  >
                    <X aria-hidden className="size-4" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="grid gap-3">
        <h2 className="font-heading text-lg font-semibold text-foreground">
          Mes amis <span className="text-sm font-normal text-muted-foreground">({data.friends.length})</span>
        </h2>
        {data.friends.length === 0 ? (
          <p className="text-sm text-muted-foreground">Pas encore d&apos;amis — visite des profils pour envoyer des demandes.</p>
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2" role="list">
            {data.friends.map((card) => (
              <li className="rounded-lg border border-border bg-surface px-3 py-2" key={card.userId}>
                <FriendIdentity card={card} link />
              </li>
            ))}
          </ul>
        )}
      </section>

      {data.outgoing.length > 0 ? (
        <section className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground">
            Demandes envoyées <span className="text-sm font-normal text-muted-foreground">({data.outgoing.length})</span>
          </h2>
          <ul className="grid gap-2 sm:grid-cols-2" role="list">
            {data.outgoing.map((card) => (
              <li className="rounded-lg border border-border bg-surface/60 px-3 py-2" key={card.userId}>
                <FriendIdentity card={card} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}

function FriendIdentity({ card, link = false }: { card: FriendCard; link?: boolean }) {
  const name = card.displayName ?? card.slug;
  const inner = (
    <span className="flex min-w-0 flex-1 items-center gap-3">
      <span
        aria-hidden
        className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent/15 text-sm font-bold text-accent-text"
      >
        {card.avatarUrl ? (
          // eslint-disable-next-line @next/next/no-img-element -- external Discord/Steam avatar
          <img alt={name} className="size-full object-cover" src={card.avatarUrl} />
        ) : (
          name.slice(0, 1).toUpperCase()
        )}
      </span>
      <span className="min-w-0 truncate text-sm font-medium text-foreground">{name}</span>
    </span>
  );

  return link ? (
    <Link className="flex items-center gap-3 hover:text-accent-text" href={`/joueurs/${card.slug}`}>
      {inner}
    </Link>
  ) : (
    inner
  );
}
