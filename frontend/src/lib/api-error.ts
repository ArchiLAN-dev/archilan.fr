/**
 * The message to show for an API error envelope (`{ error: { message, details } }`): the first field detail when there
 * is one (it says what to fix), else the general message, else null. Story 5.9.
 */
export function apiErrorMessage(payload: unknown): string | null {
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return null;
  const error: unknown = payload.error;
  if (typeof error !== "object" || error === null) return null;
  if ("details" in error && typeof error.details === "object" && error.details !== null) {
    for (const messages of Object.values(error.details)) {
      if (Array.isArray(messages) && typeof messages[0] === "string") return messages[0];
    }
  }
  return "message" in error && typeof error.message === "string" ? error.message : null;
}
