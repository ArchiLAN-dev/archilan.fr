import { ExternalLink } from "lucide-react";

/**
 * Shared video embed (story 10.11), generalised from the tutorial step's `StepVideo`.
 *
 * Only an allow-listed host is ever framed - YouTube today - and anything else degrades to a plain
 * link. That allow-list is the point: markdown content must never be able to produce an arbitrary
 * iframe. The hardening is carried over verbatim from the tutorial implementation: the nocookie
 * host, a sandbox, a strict referrer policy, and no autoplay.
 */

/** The 11-char YouTube id from any of its URL shapes, or null when this is not a YouTube link. */
export function youtubeId(url: string): string | null {
  const match = url.match(
    /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^&]*&)*v=|embed\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/,
  );
  return match ? match[1] : null;
}

/** True when the URL is a host we are willing to frame. */
export function isEmbeddableVideo(url: string): boolean {
  return youtubeId(url) !== null;
}

export function VideoEmbed({ url, title = "Vidéo" }: { url: string; title?: string }) {
  const id = youtubeId(url);

  if (id === null) {
    return (
      <a
        className="inline-flex w-fit items-center gap-2 text-accent-text underline-offset-2 hover:underline"
        href={url}
        rel="noopener noreferrer"
        target="_blank"
      >
        Voir la vidéo
        <ExternalLink aria-hidden="true" className="size-3.5" />
      </a>
    );
  }

  return (
    <div className="my-3 aspect-video w-full max-w-xl overflow-hidden rounded border border-border">
      <iframe
        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowFullScreen
        className="h-full w-full"
        referrerPolicy="strict-origin-when-cross-origin"
        sandbox="allow-scripts allow-same-origin allow-presentation"
        src={`https://www.youtube-nocookie.com/embed/${id}`}
        title={title}
      />
    </div>
  );
}
