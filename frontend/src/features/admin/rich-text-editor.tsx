"use client";

import {
  Bold,
  Code,
  Heading2,
  Heading3,
  Image as ImageIcon,
  Italic,
  Link,
  List,
  ListOrdered,
  Minus,
  Quote,
  Redo2,
  Undo2,
  Video,
} from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { useEditor, EditorContent, type Editor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import ImageExtension from "@tiptap/extension-image";
import LinkExtension from "@tiptap/extension-link";
import Placeholder from "@tiptap/extension-placeholder";
import YoutubeExtension from "@tiptap/extension-youtube";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ── Toolbar button ────────────────────────────────────────────────────────────

type ToolbarButtonProps = {
  active?: boolean;
  disabled?: boolean;
  label: string;
  onClick: () => void;
  children: React.ReactNode;
};

function ToolbarButton({ active, disabled, label, onClick, children }: ToolbarButtonProps) {
  return (
    <button
      aria-label={label}
      aria-pressed={active}
      className={[
        "flex size-8 items-center justify-center rounded transition-colors",
        active
          ? "bg-accent text-white"
          : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
        disabled ? "cursor-not-allowed opacity-40" : "",
      ].join(" ")}
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}

function Divider() {
  return <div aria-hidden className="mx-1 h-5 w-px bg-border" />;
}

// ── Toolbar ───────────────────────────────────────────────────────────────────

type ToolbarProps = {
  editor: Editor;
  postId?: string;
};

function Toolbar({ editor, postId }: ToolbarProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [, setUploading] = useState(false);

  const setLink = useCallback(() => {
    const prev = editor.getAttributes("link").href as string | undefined;
    const url = window.prompt("URL du lien", prev ?? "https://");
    if (url === null) return;
    if (url === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
    } else {
      editor.chain().focus().extendMarkRange("link").setLink({ href: url }).run();
    }
  }, [editor]);

  const insertImageUrl = useCallback(() => {
    const url = window.prompt("URL de l'image", "https://");
    if (!url) return;
    editor.chain().focus().setImage({ src: url }).run();
  }, [editor]);

  const insertVideo = useCallback(() => {
    const url = window.prompt("URL YouTube ou Vimeo", "https://www.youtube.com/watch?v=");
    if (!url) return;
    editor.chain().focus().setYoutubeVideo({ src: url, width: 640, height: 360 }).run();
  }, [editor]);

  const handleFileUpload = useCallback(async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !postId) return;
    e.target.value = "";
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append("file", file);
      const res = await apiFetch(`${env.apiBaseUrl}/admin/posts/${postId}/inline-image`, {
        method: "POST",
        body: fd,
      });
      if (res.ok) {
        const payload = await res.json() as { data?: { url: string } };
        const url = payload.data?.url;
        if (url) editor.chain().focus().setImage({ src: url }).run();
      }
    } finally {
      setUploading(false);
    }
  }, [editor, postId, setUploading]);

  return (
    <div className="flex flex-wrap items-center gap-0.5 border-b border-border bg-surface-2/60 px-2 py-1.5">
      {/* Headings */}
      <ToolbarButton
        active={editor.isActive("heading", { level: 2 })}
        label="Titre H2"
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
      >
        <Heading2 className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("heading", { level: 3 })}
        label="Titre H3"
        onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
      >
        <Heading3 className="size-4" />
      </ToolbarButton>

      <Divider />

      {/* Inline marks */}
      <ToolbarButton
        active={editor.isActive("bold")}
        label="Gras"
        onClick={() => editor.chain().focus().toggleBold().run()}
      >
        <Bold className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("italic")}
        label="Italique"
        onClick={() => editor.chain().focus().toggleItalic().run()}
      >
        <Italic className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("code")}
        label="Code inline"
        onClick={() => editor.chain().focus().toggleCode().run()}
      >
        <Code className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("link")}
        label="Lien"
        onClick={setLink}
      >
        <Link className="size-4" />
      </ToolbarButton>

      <Divider />

      {/* Lists & blocks */}
      <ToolbarButton
        active={editor.isActive("bulletList")}
        label="Liste à puces"
        onClick={() => editor.chain().focus().toggleBulletList().run()}
      >
        <List className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("orderedList")}
        label="Liste numérotée"
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
      >
        <ListOrdered className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        active={editor.isActive("blockquote")}
        label="Citation"
        onClick={() => editor.chain().focus().toggleBlockquote().run()}
      >
        <Quote className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        label="Séparateur"
        onClick={() => editor.chain().focus().setHorizontalRule().run()}
      >
        <Minus className="size-4" />
      </ToolbarButton>

      <Divider />

      {/* Media */}
      <ToolbarButton label="Image par URL" onClick={insertImageUrl}>
        <ImageIcon aria-hidden className="size-4" />
      </ToolbarButton>

      {/* Upload image - only available when postId is known (edit mode) */}
      {postId && (
        <label
          className="flex size-8 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-surface-2 hover:text-foreground"
          title="Uploader une image"
        >
          <span className="sr-only">Uploader une image</span>
          <ImageIcon aria-hidden className="size-4 opacity-60" />
          <span aria-hidden className="ml-0.5 text-[9px] font-bold leading-none">↑</span>
          <input
            accept="image/jpeg,image/png,image/webp,image/gif"
            className="sr-only"
            onChange={(e) => { void handleFileUpload(e); }}
            ref={fileInputRef}
            type="file"
          />
        </label>
      )}

      <ToolbarButton label="Vidéo YouTube / Vimeo" onClick={insertVideo}>
        <Video aria-hidden className="size-4" />
      </ToolbarButton>

      <Divider />

      {/* History */}
      <ToolbarButton
        disabled={!editor.can().chain().focus().undo().run()}
        label="Annuler"
        onClick={() => editor.chain().focus().undo().run()}
      >
        <Undo2 className="size-4" />
      </ToolbarButton>
      <ToolbarButton
        disabled={!editor.can().chain().focus().redo().run()}
        label="Rétablir"
        onClick={() => editor.chain().focus().redo().run()}
      >
        <Redo2 className="size-4" />
      </ToolbarButton>
    </div>
  );
}

// ── Editor ────────────────────────────────────────────────────────────────────

type Props = {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  error?: string;
  postId?: string;
};

export function RichTextEditor({ value, onChange, placeholder, error, postId }: Props) {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        codeBlock: false,
      }),
      LinkExtension.configure({
        openOnClick: false,
        HTMLAttributes: { rel: "noopener noreferrer" },
      }),
      ImageExtension.configure({
        allowBase64: false,
        HTMLAttributes: { class: "rich-editor-image" },
      }),
      YoutubeExtension.configure({
        controls: true,
        nocookie: true,
      }),
      Placeholder.configure({
        placeholder: placeholder ?? "Rédigez votre article…",
      }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: "min-h-64 px-4 py-3 outline-none",
      },
    },
    onUpdate({ editor: e }) {
      onChange(e.getHTML());
    },
  });

  // Sync external value changes into the editor (e.g. when edit form loads async data).
  // emitUpdate=false prevents triggering onChange → infinite loop.
  const prevValueRef = useRef(value);
  useEffect(() => {
    if (!editor || value === prevValueRef.current) return;
    prevValueRef.current = value;
    if (editor.getHTML() !== value) {
      editor.commands.setContent(value);
    }
  }, [editor, value]);

  if (!editor) return null;

  return (
    <div
      className={[
        "border bg-background",
        error ? "border-danger" : "border-border focus-within:border-accent",
      ].join(" ")}
    >
      <Toolbar editor={editor} postId={postId} />
      <div className="rich-editor">
        <EditorContent editor={editor} />
      </div>
      {error && (
        <p className="border-t border-danger/30 px-3 py-1.5 text-xs text-danger">{error}</p>
      )}
    </div>
  );
}
