"use client";

import { useEffect, useRef } from "react";

import type { CompatibilityReport } from "@/features/admin/admin-tracker-packs-api";

import { ENGINE_VERSION } from "../engine/engine-version";
import type { PackMessage, TrackerSessionPhase } from "./use-tracker-session";

/**
 * Story 54.8: what an admin's try of a variant found, read from the screen's own state: the load failed, or it loaded
 * with the errors and missing functions the pack reported (the same ones the error panel shows).
 */
const MAX_ERRORS = 50;
const MAX_APIS = 100;
/** Story 54.13: an error message is pack text (`error(...)`), cut before it leaves the browser. */
export const MAX_MESSAGE_LENGTH = 500;

function cut(message: string): string {
  return message.length > MAX_MESSAGE_LENGTH ? `${message.slice(0, MAX_MESSAGE_LENGTH - 1)}…` : message;
}
const UNSUPPORTED_API = /^Fonction non prise en charge : (.+)$/;
const UNSUPPORTED_WIDGET = /^Élément d'affichage non géré : (.+)$/;

/** How long after the load the report waits for the errors the first drawing and settling bring. */
export const REPORT_DELAY_MS = 1500;

export function compatibilityReport(
  variantUid: string,
  phase: TrackerSessionPhase,
  messages: PackMessage[],
): CompatibilityReport | null {
  if (phase.kind === "failed") {
    return { variantUid, status: "failed", errors: [{ kind: "load", message: cut(phase.message) }], unsupportedApis: [], engineVersion: ENGINE_VERSION };
  }
  if (phase.kind !== "ready") return null;
  const apis = new Set<string>();
  const errors: CompatibilityReport["errors"] = [];
  for (const { message } of messages) {
    const api = UNSUPPORTED_API.exec(message);
    const widget = UNSUPPORTED_WIDGET.exec(message);
    if (api !== null) apis.add(cut(api[1] ?? message));
    else if (widget !== null) apis.add(cut(`widget:${widget[1] ?? ""}`));
    else if (errors.length < MAX_ERRORS) errors.push({ kind: "error", message: cut(message) });
  }
  const unsupportedApis = [...apis].slice(0, MAX_APIS);
  const status = errors.length === 0 && unsupportedApis.length === 0 ? "ok" : "warnings";
  return { variantUid, status, errors, unsupportedApis, engineVersion: ENGINE_VERSION };
}

/** Sends one report per session once its load is over, when the screen runs as a trial. */
export function useLoadReport(
  onReport: ((report: CompatibilityReport) => void) | undefined,
  variantUid: string,
  phase: TrackerSessionPhase,
  messages: PackMessage[],
): void {
  const sent = useRef(false);
  const latest = useRef(messages);
  useEffect(() => {
    latest.current = messages;
  }, [messages]);
  const over = phase.kind === "ready" || phase.kind === "failed";
  useEffect(() => {
    if (onReport === undefined || !over || sent.current) return;
    const timer = setTimeout(() => {
      const report = compatibilityReport(variantUid, phase, latest.current);
      if (report === null) return;
      sent.current = true;
      onReport(report);
    }, REPORT_DELAY_MS);
    return () => clearTimeout(timer);
  }, [onReport, over, phase, variantUid]);
}
