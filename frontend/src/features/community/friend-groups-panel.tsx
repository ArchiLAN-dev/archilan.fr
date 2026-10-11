"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Trash2, Users, X } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import type { FriendCard } from "./community-friends-api";
import {
  addToFriendGroup,
  createFriendGroup,
  deleteFriendGroup,
  fetchFriendGroups,
  FRIEND_GROUPS_KEY,
  removeFromFriendGroup,
  renameFriendGroup,
  type FriendGroup,
  type FriendGroupsResult,
} from "./friend-groups-api";

export const MAX_FRIEND_GROUPS = 20;
export const MAX_GROUP_MEMBERS = 30;

export function useFriendGroups(enabled = true) {
  return useQuery({ queryKey: FRIEND_GROUPS_KEY, queryFn: fetchFriendGroups, enabled, staleTime: DEFAULT_STALE_TIME, retry: false });
}

/**
 * « Mes groupes » (story 43.13): named groups of friends, private to the member, to invite them all at once and to
 * filter the directory. A friend may be in several groups.
 */
export function FriendGroupsPanel({ friends }: { friends: FriendCard[] }) {
  const queryClient = useQueryClient();
  const { data: groups } = useFriendGroups();
  const [name, setName] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  async function apply(write: Promise<FriendGroupsResult>): Promise<boolean> {
    const result = await write;
    if (!result.ok) {
      setMessage(result.message);
      return false;
    }
    setMessage(null);
    queryClient.setQueryData(FRIEND_GROUPS_KEY, result.groups);
    return true;
  }

  if (groups === undefined || groups === null || friends.length === 0) return null;

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">
        Mes groupes <span className="text-sm font-normal text-muted-foreground">({groups.length})</span>
      </h2>
      <p className="text-sm text-muted-foreground">
        Rien que pour toi : tes amis ne voient pas les groupes où tu les ranges. Un groupe s&apos;invite d&apos;un coup dans une partie.
      </p>
      {groups.length < MAX_FRIEND_GROUPS ? (
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            void apply(createFriendGroup(name)).then((ok) => {
              if (ok) setName("");
            });
          }}
        >
          <input
            aria-label="Nom du nouveau groupe"
            className="min-h-9 min-w-0 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground"
            maxLength={60}
            onChange={(e) => setName(e.target.value)}
            placeholder="La team du jeudi"
            value={name}
          />
          <button
            className="inline-flex min-h-9 items-center gap-1.5 rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
            disabled={name.trim() === ""}
            type="submit"
          >
            <Plus aria-hidden className="size-4" />
            Créer
          </button>
        </form>
      ) : (
        <p className="text-xs text-muted-foreground">Tu as atteint les {MAX_FRIEND_GROUPS} groupes.</p>
      )}
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
      {groups.length > 0 ? (
        <ul className="grid gap-3" role="list">
          {groups.map((group) => (
            <FriendGroupCard apply={apply} friends={friends} group={group} key={group.id} />
          ))}
        </ul>
      ) : null}
    </section>
  );
}

function FriendGroupCard({
  group,
  friends,
  apply,
}: {
  group: FriendGroup;
  friends: FriendCard[];
  apply: (write: Promise<FriendGroupsResult>) => Promise<boolean>;
}) {
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(group.name);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const byId = new Map(friends.map((friend) => [friend.userId, friend]));
  const members = group.memberIds.flatMap((id) => {
    const card = byId.get(id);
    return card === undefined ? [] : [card];
  });
  const addable = friends.filter((friend) => !group.memberIds.includes(friend.userId));

  return (
    <li className="grid gap-2 rounded-lg border border-border bg-surface px-3 py-3">
      <div className="flex items-center gap-2">
        <Users aria-hidden className="size-4 shrink-0 text-accent-text" />
        {renaming ? (
          <form
            className="flex min-w-0 flex-1 gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              void apply(renameFriendGroup(group.id, name)).then((ok) => {
                if (ok) setRenaming(false);
              });
            }}
          >
            <input
              aria-label="Nouveau nom du groupe"
              className="min-h-8 min-w-0 flex-1 rounded border border-border bg-background px-2 text-sm text-foreground"
              maxLength={60}
              onChange={(e) => setName(e.target.value)}
              value={name}
            />
            <button className="rounded border border-border px-2 text-xs font-semibold text-foreground hover:border-accent" type="submit">
              OK
            </button>
          </form>
        ) : (
          <p className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">
            {group.name} <span className="font-normal text-muted-foreground">({members.length})</span>
          </p>
        )}
        {!renaming ? (
          <button
            aria-label={`Renommer ${group.name}`}
            className="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:text-foreground"
            onClick={() => {
              setName(group.name);
              setRenaming(true);
            }}
            type="button"
          >
            <Pencil aria-hidden className="size-3.5" />
          </button>
        ) : null}
        <button
          aria-label={`Supprimer ${group.name}`}
          className="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:text-danger"
          onClick={() => setConfirmDelete(true)}
          type="button"
        >
          <Trash2 aria-hidden className="size-3.5" />
        </button>
      </div>
      {members.length > 0 ? (
        <ul className="flex flex-wrap gap-1.5" role="list">
          {members.map((member) => (
            <li className="inline-flex items-center gap-1 rounded-full border border-border py-0.5 pl-2.5 pr-1 text-xs text-foreground" key={member.userId}>
              {member.displayName ?? member.slug}
              <button
                aria-label={`Retirer ${member.displayName ?? member.slug} du groupe`}
                className="inline-flex size-5 items-center justify-center rounded-full text-muted-foreground hover:text-foreground"
                onClick={() => {
                  void apply(removeFromFriendGroup(group.id, member.userId));
                }}
                type="button"
              >
                <X aria-hidden className="size-3" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-muted-foreground">Personne pour l&apos;instant.</p>
      )}
      {addable.length > 0 && members.length < MAX_GROUP_MEMBERS ? (
        <select
          aria-label={`Ajouter un ami à ${group.name}`}
          className="min-h-8 w-full rounded border border-border bg-background px-2 text-xs text-foreground sm:w-auto"
          onChange={(e) => {
            const userId = e.target.value;
            e.target.value = "";
            if (userId !== "") void apply(addToFriendGroup(group.id, userId));
          }}
          value=""
        >
          <option value="">Ajouter un ami…</option>
          {addable.map((friend) => (
            <option key={friend.userId} value={friend.userId}>
              {friend.displayName ?? friend.slug}
            </option>
          ))}
        </select>
      ) : null}
      <ConfirmDialog
        confirmLabel="Supprimer le groupe"
        description="Tes amis restent tes amis, seul le groupe disparaît."
        icon={Trash2}
        onConfirm={() => {
          setConfirmDelete(false);
          void apply(deleteFriendGroup(group.id));
        }}
        onOpenChange={setConfirmDelete}
        open={confirmDelete}
        title={`Supprimer « ${group.name} » ?`}
        tone="danger"
      />
    </li>
  );
}
