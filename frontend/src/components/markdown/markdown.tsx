import ReactMarkdown, { type Components } from "react-markdown";
import remarkBreaks from "remark-breaks";
import remarkGfm from "remark-gfm";

import { isEmbeddableVideo, VideoEmbed } from "./video-embed";

/**
 * Renders authored markdown (story 10.10).
 *
 * SECURITY: react-markdown produces React elements, never an HTML string, so raw HTML in the source
 * is inert by construction - `<script>` or `<img onerror>` typed by an author renders as text. The
 * project has no sanitiser anywhere, and some of these fields are community-authored, so this is the
 * property the whole feature rests on: **never add `rehype-raw`** (or any rehype plugin re-enabling
 * raw HTML) to this component.
 *
 * `remarkBreaks` keeps single newlines as line breaks: several surfaces (bio, commentaires, étapes de
 * tutoriel) render with `whitespace-pre-line` today, and standard markdown would silently reflow every
 * existing text without it.
 */

type Props = {
  children: string | null | undefined;
  /** Inline subset (no headings, lists or blockquotes) for dense layouts like the achievement card. */
  inline?: boolean;
  /** Community-authored content: links get nofollow/ugc and images are dropped. */
  untrusted?: boolean;
  className?: string;
};

const PLUGINS = [remarkGfm, remarkBreaks];

function linkComponent(untrusted: boolean): Components["a"] {
  return function Anchor({ href, children }) {
    // Only http(s) survives: javascript:/data: hrefs are rendered as plain text instead of a link.
    const safe = typeof href === "string" && /^https?:\/\//i.test(href) ? href : undefined;
    if (safe === undefined) return <>{children}</>;

    return (
      <a
        className="text-accent-text underline underline-offset-2 hover:no-underline"
        href={safe}
        rel={untrusted ? "nofollow ugc noopener noreferrer" : "noopener noreferrer"}
        target="_blank"
      >
        {children}
      </a>
    );
  };
}

/**
 * The URL of a paragraph that is nothing but a single link, or null.
 *
 * Read off the hast node rather than the rendered children: "this paragraph is exactly one link" is
 * a structural fact, and guessing it from React elements would break the moment the anchor component
 * changes. A URL written mid-sentence therefore never turns into a player (story 10.11).
 */
function loneLinkUrl(node: unknown): string | null {
  if (typeof node !== "object" || node === null || !("children" in node)) return null;
  const children = (node as { children: unknown }).children;
  if (!Array.isArray(children)) return null;

  const meaningful = children.filter(
    (c) => !(typeof c === "object" && c !== null && "type" in c && (c as { type: unknown }).type === "text"
      && typeof (c as { value?: unknown }).value === "string"
      && ((c as { value: string }).value).trim() === ""),
  );
  if (meaningful.length !== 1) return null;

  const only = meaningful[0];
  if (typeof only !== "object" || only === null) return null;
  const el = only as { type?: unknown; tagName?: unknown; properties?: { href?: unknown } };
  if (el.type !== "element" || el.tagName !== "a") return null;

  return typeof el.properties?.href === "string" ? el.properties.href : null;
}

/** Headings start at h3: authored content must never outrank the page's own h1/h2. */
const BLOCK_COMPONENTS: Components = {
  // Paragraphs must stay real <p>s here. Flattening them (as the inline variant does) glues
  // consecutive paragraphs together with no separation - the callers put their text styling on the
  // wrapper, so only the spacing belongs on the element itself.
  p: ({ children }) => <p className="my-2 first:mt-0 last:mb-0">{children}</p>,
  h1: ({ children }) => <h3 className="mt-4 font-heading text-lg font-bold text-foreground">{children}</h3>,
  h2: ({ children }) => <h3 className="mt-4 font-heading text-base font-bold text-foreground">{children}</h3>,
  h3: ({ children }) => <h4 className="mt-3 font-heading text-base font-semibold text-foreground">{children}</h4>,
  h4: ({ children }) => <h5 className="mt-3 font-semibold text-foreground">{children}</h5>,
  h5: ({ children }) => <h5 className="mt-3 font-semibold text-foreground">{children}</h5>,
  h6: ({ children }) => <h6 className="mt-3 font-semibold text-foreground">{children}</h6>,
  ul: ({ children }) => <ul className="my-2 list-disc space-y-1 pl-5">{children}</ul>,
  ol: ({ children }) => <ol className="my-2 list-decimal space-y-1 pl-5">{children}</ol>,
  blockquote: ({ children }) => (
    <blockquote className="my-2 border-l-2 border-border pl-3 italic">{children}</blockquote>
  ),
  hr: () => <hr className="my-4 border-border" />,
};

/**
 * Marks that are safe in any layout, including a one-line card. Block-level elements are NOT here:
 * `p` in particular is overridden per variant - flattened inline, kept as a real paragraph in block.
 */
const INLINE_COMPONENTS: Components = {
  strong: ({ children }) => <strong className="font-semibold text-foreground">{children}</strong>,
  em: ({ children }) => <em className="italic">{children}</em>,
  code: ({ children }) => (
    <code className="rounded bg-surface-2 px-1 py-0.5 font-mono text-[0.9em] text-accent-text">{children}</code>
  ),
};

export function Markdown({ children, inline = false, untrusted = false, className }: Props) {
  if (children === null || children === undefined || children.trim() === "") return null;

  const components: Components = {
    ...INLINE_COMPONENTS,
    ...(inline
      ? // Strip block structure down to its text so a dense card never grows a heading or a list.
        {
          p: ({ children: c }) => <>{c}</>,
          h1: ({ children: c }) => <>{c}</>,
          h2: ({ children: c }) => <>{c}</>,
          h3: ({ children: c }) => <>{c}</>,
          h4: ({ children: c }) => <>{c}</>,
          h5: ({ children: c }) => <>{c}</>,
          h6: ({ children: c }) => <>{c}</>,
          ul: ({ children: c }) => <>{c}</>,
          ol: ({ children: c }) => <>{c}</>,
          li: ({ children: c }) => <> {c}</>,
          blockquote: ({ children: c }) => <>{c}</>,
          hr: () => null,
        }
      : BLOCK_COMPONENTS),
    // A lone video URL becomes a player. Block content only (an embed cannot live inside a dense
    // inline card), and trusted content only: on untrusted surfaces it stays a link, matching the
    // images-are-dropped policy - the exposure there is moderation, not code (story 10.11).
    ...(!inline && !untrusted
      ? {
          p: ({ children, node }) => {
            const url = loneLinkUrl(node);
            if (url !== null && isEmbeddableVideo(url)) {
              return <VideoEmbed url={url} />;
            }

            return <p className="my-2 first:mt-0 last:mb-0">{children}</p>;
          },
        }
      : {}),
    a: linkComponent(untrusted),
    // Remote images are a tracking/abuse vector from untrusted authors; drop them entirely there.
    img: untrusted
      ? () => null
      : ({ src, alt }) =>
          typeof src === "string" && /^https?:\/\//i.test(src) ? (
            // eslint-disable-next-line @next/next/no-img-element -- authored URLs are arbitrary remote hosts
            <img alt={alt ?? ""} className="my-2 h-auto max-w-full rounded" src={src} />
          ) : null,
  };

  if (inline) {
    return (
      <span className={className}>
        <ReactMarkdown components={components} remarkPlugins={PLUGINS}>
          {children}
        </ReactMarkdown>
      </span>
    );
  }

  return (
    <div className={className}>
      <ReactMarkdown components={components} remarkPlugins={PLUGINS}>
        {children}
      </ReactMarkdown>
    </div>
  );
}
