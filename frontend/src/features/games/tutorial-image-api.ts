import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

export type UploadedTutorialImage = { key: string; url: string };

/**
 * Uploads a tutorial-step image (story 31.10) and returns its MinIO key plus a presigned preview URL,
 * or null on any failure (bad type/size, auth, network). The key is what the editor persists on the step.
 */
export async function uploadTutorialImage(file: File): Promise<UploadedTutorialImage | null> {
  try {
    const body = new FormData();
    body.append("file", file);

    const response = await apiFetch(`${env.apiBaseUrl}/tutorial-images`, { method: "POST", body });
    if (!response.ok) return null;

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data = payload.data;
    if (typeof data !== "object" || data === null || !hasStringProp(data, "key") || !hasStringProp(data, "url")) {
      return null;
    }

    return { key: data.key, url: data.url };
  } catch {
    return null;
  }
}
