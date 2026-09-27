"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import {
  fetchAccountModerationContact,
  fetchBlockedModerationContact,
  sendAccountModerationMessage,
  sendBlockedModerationMessage,
  type SendResult,
} from "./moderation-contact-api";
import { ModerationContactThread, sanctionLine } from "./moderation-contact-thread";

const BLOCKED_KEY = ["moderation-contact", "blocked"];
const ACCOUNT_KEY = ["moderation-contact", "account"];

function useSend(send: (body: string) => Promise<SendResult>, queryKey: string[]) {
  const queryClient = useQueryClient();
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const mutation = useMutation({
    mutationFn: send,
    onMutate: () => {
      setSent(false);
      setError(null);
    },
    onSuccess: async (result) => {
      if (result.ok) {
        setSent(true);
        await queryClient.invalidateQueries({ queryKey });
      } else {
        setError(result.message);
      }
    },
  });

  return {
    send: async (body: string) => (await mutation.mutateAsync(body)).ok,
    sending: mutation.isPending,
    sent,
    error,
  };
}

/**
 * Story 39.2 : après une connexion refusée (banni ou suspendu), le membre écrit à la modération avec le
 * laissez-passer posé par l'API. Story 39.9 : tant que le fil n'est pas là (chargement, laissez-passer absent),
 * le message du refus sert de repli ; une fois là, il le remplace, la sanction n'étant dite qu'une fois. Relu à
 * chaque refus : un nouveau refus porte un nouveau laissez-passer.
 */
export function BlockedModerationContact({ fallback }: { fallback: string | null }) {
  const { data } = useQuery({
    queryKey: BLOCKED_KEY,
    queryFn: fetchBlockedModerationContact,
    retry: false,
    // Never the thread of an earlier refusal: dropped as soon as the block goes.
    staleTime: 0,
    gcTime: 0,
  });
  const { send, sending, sent, error } = useSend(sendBlockedModerationMessage, BLOCKED_KEY);

  if (!data) {
    return fallback !== null ? (
      <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="alert">
        {fallback}
      </p>
    ) : null;
  }

  return (
    <ModerationContactThread
      error={error}
      intro={sanctionLine(data)}
      messages={data.messages}
      onSend={send}
      sending={sending}
      sent={sent}
    />
  );
}

/** Story 39.2 : dans l'espace compte, seulement pour un membre qui a déjà reçu une sanction. */
export function AccountModerationContact() {
  const { data } = useQuery({ queryKey: ACCOUNT_KEY, queryFn: fetchAccountModerationContact, retry: false });
  const { send, sending, sent, error } = useSend(sendAccountModerationMessage, ACCOUNT_KEY);

  if (!data?.available) return null;

  return <ModerationContactThread error={error} intro={null} messages={data.messages} onSend={send} sending={sending} sent={sent} />;
}
