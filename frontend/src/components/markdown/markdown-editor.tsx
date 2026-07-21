"use client";

import { Bold, Code, Eye, Heading, Italic, Link2, List, Pencil } from "lucide-react";
import { useRef, useState } from "react";

import { Markdown } from "./markdown";

/**
 * Light markdown editor (story 10.10): the source stays visible in a textarea, with a small toolbar
 * and an *Aperçu* toggle. Deliberately not a WYSIWYG - the brief was "rien de trop complexe", and a
 * second rich-editor framework (TipTap already backs the news posts) would be a heavier commitment.
 *
 * The preview renders through the same `Markdown` component as the public pages, so what the author
 * sees cannot drift from what visitors get.
 */

type Props = {
  value: string;
  onChange: (value: string) => void;
  /** Mirrors the render-side policy so the preview matches the real output. */
  untrusted?: boolean;
  placeholder?: string;
  maxLength?: number;
  rows?: number;
  id?: string;
  className?: string;
};

type Wrap = { before: string; after: string; placeholder: string };
type LinePrefix = { prefix: string; placeholder: string };

const BUTTON_CLS =
  "inline-flex size-7 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground";

export function MarkdownEditor({
  value,
  onChange,
  untrusted = false,
  placeholder,
  maxLength,
  rows = 6,
  id,
  className,
}: Props) {
  const [preview, setPreview] = useState(false);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  /** Wraps the selection (or inserts a placeholder) and restores focus + selection. */
  function applyWrap({ before, after, placeholder: ph }: Wrap): void {
    const el = textareaRef.current;
    if (!el) return;

    const { selectionStart: start, selectionEnd: end } = el;
    const selected = value.slice(start, end) || ph;
    const next = `${value.slice(0, start)}${before}${selected}${after}${value.slice(end)}`;
    if (maxLength !== undefined && next.length > maxLength) return;

    onChange(next);
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(start + before.length, start + before.length + selected.length);
    });
  }

  /** Prefixes the line the caret sits on (headings, bullets). */
  function applyLinePrefix({ prefix, placeholder: ph }: LinePrefix): void {
    const el = textareaRef.current;
    if (!el) return;

    const { selectionStart: start, selectionEnd: end } = el;
    const lineStart = value.lastIndexOf("\n", start - 1) + 1;
    const selected = value.slice(start, end);
    const body = selected || (value.slice(lineStart, start).trim() === "" ? ph : "");
    const next = `${value.slice(0, lineStart)}${prefix}${value.slice(lineStart, start)}${body}${value.slice(end)}`;
    if (maxLength !== undefined && next.length > maxLength) return;

    onChange(next);
    requestAnimationFrame(() => {
      el.focus();
      const caret = start + prefix.length;
      el.setSelectionRange(caret, caret + body.length);
    });
  }

  return (
    <div className={className}>
      <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
        <button
          aria-label="Gras"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyWrap({ before: "**", after: "**", placeholder: "gras" })}
          title="Gras"
          type="button"
        >
          <Bold aria-hidden className="size-3.5" />
        </button>
        <button
          aria-label="Italique"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyWrap({ before: "*", after: "*", placeholder: "italique" })}
          title="Italique"
          type="button"
        >
          <Italic aria-hidden className="size-3.5" />
        </button>
        <button
          aria-label="Titre"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyLinePrefix({ prefix: "### ", placeholder: "Titre" })}
          title="Titre"
          type="button"
        >
          <Heading aria-hidden className="size-3.5" />
        </button>
        <button
          aria-label="Liste"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyLinePrefix({ prefix: "- ", placeholder: "élément" })}
          title="Liste"
          type="button"
        >
          <List aria-hidden className="size-3.5" />
        </button>
        <button
          aria-label="Lien"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyWrap({ before: "[", after: "](https://)", placeholder: "texte" })}
          title="Lien"
          type="button"
        >
          <Link2 aria-hidden className="size-3.5" />
        </button>
        <button
          aria-label="Code"
          className={BUTTON_CLS}
          disabled={preview}
          onClick={() => applyWrap({ before: "`", after: "`", placeholder: "code" })}
          title="Code"
          type="button"
        >
          <Code aria-hidden className="size-3.5" />
        </button>

        <button
          aria-pressed={preview}
          className="ml-auto inline-flex min-h-7 items-center gap-1.5 rounded border border-border px-2 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          onClick={() => setPreview((p) => !p)}
          type="button"
        >
          {preview ? <Pencil aria-hidden className="size-3" /> : <Eye aria-hidden className="size-3" />}
          {preview ? "Éditer" : "Aperçu"}
        </button>
      </div>

      {preview ? (
        <div className="min-h-24 rounded border border-border bg-background px-3 py-2 text-sm text-muted-foreground">
          {value.trim() === "" ? (
            <p className="text-muted-foreground/60">Rien à prévisualiser.</p>
          ) : (
            <Markdown untrusted={untrusted}>{value}</Markdown>
          )}
        </div>
      ) : (
        <textarea
          className="w-full rounded border border-border bg-background px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
          id={id}
          maxLength={maxLength}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          ref={textareaRef}
          rows={rows}
          value={value}
        />
      )}

      <p className="mt-1 text-xs text-muted-foreground/70">
        Markdown supporté : **gras**, *italique*, ### titre, - liste, [lien](url), `code`.
      </p>
    </div>
  );
}
