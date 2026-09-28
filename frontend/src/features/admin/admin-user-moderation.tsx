"use client";

import { useId, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Gavel, Loader2, MessageSquareReply, NotebookPen } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

import { fetchAdminUserModeration, hasPendingDiscordOutcome, replyToMember, type ModerationCommand } from "./admin-users-api";
import { ModerationActionList, ModerationCaseMessages, ModerationStateBanner } from "./moderation-history";
import { SanctionDialog } from "./sanction-dialog";

type Props = {
  userId: string;
  /** Shown in the dialog titles. */
  name: string;
  /** An admin account cannot be moderated - the server refuses it, so the UI says so up front. */
  isAdmin: boolean;
  isSelf: boolean;
};

/**
 * Moderation panel of the admin user sheet (story 36.2). Story 39.11: the state, a bar of actions that open
 * their own window, then the case messages and the history as flat lists.
 */
export function AdminUserModeration({ userId, name, isAdmin, isSelf }: Props) {
  const queryClient = useQueryClient();
  const queryKey = ["admin-user-moderation", userId];
  const [sanction, setSanction] = useState<ModerationCommand | null>(null);
  const [replying, setReplying] = useState(false);

  const { data, isPending } = useQuery({
    queryKey,
    queryFn: () => fetchAdminUserModeration(userId),
    staleTime: DEFAULT_STALE_TIME,
    // Story 39.9: the Discord outcome of a fresh sanction or reply arrives with the async job.
    refetchInterval: (query) => (query.state.data && hasPendingDiscordOutcome(query.state.data, Date.now()) ? 5000 : false),
  });

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey });
  };

  if (isPending) {
    return (
      <Panel>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement de la modération…
        </p>
      </Panel>
    );
  }

  if (data === null || data === undefined) {
    return (
      <Panel>
        <p className="text-sm text-muted-foreground">Impossible de charger la modération de ce compte.</p>
      </Panel>
    );
  }

  const canModerate = !isAdmin && !isSelf;
  const canReply = canModerate && (data.moderationCase !== null || data.actions.length > 0);
  const messages = data.moderationCase?.messages ?? [];

  return (
    <Panel>
      <ModerationStateBanner moderation={data} />

      {canModerate ? (
        <div className="flex flex-wrap gap-2">
          <button className={buttonVariants({ variant: "primary" })} onClick={() => setSanction("warn")} type="button">
            <Gavel aria-hidden className="size-4" /> Sanctionner
          </button>
          <button className={buttonVariants({ variant: "secondary" })} onClick={() => setSanction("note")} type="button">
            <NotebookPen aria-hidden className="size-4" /> Note interne
          </button>
          {canReply ? (
            <button className={buttonVariants({ variant: "secondary" })} onClick={() => setReplying(true)} type="button">
              <MessageSquareReply aria-hidden className="size-4" /> Répondre au membre
            </button>
          ) : null}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">
          {isSelf ? "Tu ne peux pas te modérer toi-même." : "Un compte administrateur ne peut pas être modéré. Retire-lui d'abord ses droits."}
        </p>
      )}

      {messages.length > 0 ? (
        <Block title="Messages du dossier">
          <ModerationCaseMessages messages={messages} />
        </Block>
      ) : null}

      <Block title="Historique">
        <ModerationActionList actions={data.actions} />
      </Block>

      {sanction !== null ? (
        <SanctionDialog
          initialCommand={sanction}
          name={name}
          onDone={refresh}
          onOpenChange={(open) => (open ? undefined : setSanction(null))}
          open
          userId={userId}
        />
      ) : null}

      {replying ? <ReplyDialog name={name} onClose={() => setReplying(false)} onDone={refresh} userId={userId} /> : null}
    </Panel>
  );
}

/**
 * Story 39.3 : la réponse part du site, vers le membre (notification, fil, MP du bot si son compte est lié) et
 * dans le forum staff. Ce qui s'écrit dans le post du forum, lui, reste entre membres du staff.
 */
function ReplyDialog({ userId, name, onClose, onDone }: { userId: string; name: string; onClose: () => void; onDone: () => Promise<void> }) {
  const formId = useId();
  const [body, setBody] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setPending(true);
    setError(null);
    const failure = await replyToMember(userId, body);
    setPending(false);
    if (failure !== null) {
      setError(failure);
      return;
    }
    onClose();
    await onDone();
  }

  return (
    <Dialog
      description="Envoyé au membre sur le site et en MP Discord si son compte est lié, et posté dans le forum staff."
      onOpenChange={(open) => (open ? undefined : onClose())}
      open
      title={`Répondre à ${name}`}
    >
      <DialogBody>
        <form className="grid gap-2" id={formId} onSubmit={submit}>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Message</span>
            <textarea
              className="min-h-32 rounded-lg border border-border bg-background p-3 text-sm text-foreground focus:border-accent focus:outline-none"
              maxLength={2000}
              onChange={(event) => setBody(event.target.value)}
              required
              value={body}
            />
          </label>
          {error !== null ? (
            <p className="text-sm text-danger" role="alert">
              {error}
            </p>
          ) : null}
        </form>
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
          Annuler
        </button>
        <button className={buttonVariants({ variant: "primary" })} disabled={pending || body.trim() === ""} form={formId} type="submit">
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Envoyer la réponse
        </button>
      </DialogFooter>
    </Dialog>
  );
}

function Block({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-2">
      <h3 className="text-sm font-semibold text-foreground">{title}</h3>
      <div className="rounded-lg border border-border bg-surface px-4 py-3">{children}</div>
    </div>
  );
}

function Panel({ children }: { children: React.ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">Modération</h2>
      {children}
    </section>
  );
}
