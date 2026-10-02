"use client";

import { useRef, useState, type PointerEvent } from "react";
import { RotateCcw } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import {
  CENTRED_FRAMING,
  dragFraming,
  framingStyle,
  MAX_ZOOM,
  MIN_ZOOM,
  nudgeFraming,
  zoomFraming,
  type ImageFraming,
} from "./image-framing";

export type FramingShape = "avatar" | "banner";

const FRAME_CLASS: Record<FramingShape, string> = {
  // Story 30.47: the avatar is a rounded square everywhere (radius ~16.7% of its size).
  avatar: "mx-auto size-56 rounded-[16.7%]",
  banner: "aspect-[4/1] w-full rounded-lg",
};

/**
 * Story 30.43: choose the part of an uploaded image the profile shows - drag the image in the final shape (rounded
 * square photo, banner strip), zoom with the slider, or move it with the arrow keys. Nothing is cut: the framing is
 * kept with the profile and applied at display, so a GIF keeps moving.
 */
export function ImageFramingDialog({
  open,
  onOpenChange,
  imageUrl,
  shape,
  framing,
  onConfirm,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  imageUrl: string;
  shape: FramingShape;
  framing: ImageFraming;
  onConfirm: (framing: ImageFraming) => void;
}) {
  return (
    <Dialog
      description="Fais glisser l'image pour choisir ce qui est affiché, et zoome si besoin."
      onOpenChange={onOpenChange}
      open={open}
      title={shape === "avatar" ? "Recadrer la photo" : "Recadrer la bannière"}
    >
      {/* The dialog unmounts its content when closed: each opening starts from the saved framing. */}
      <ImageFramingEditor
        imageUrl={imageUrl}
        initial={framing}
        onCancel={() => onOpenChange(false)}
        onConfirm={(next) => {
          onConfirm(next);
          onOpenChange(false);
        }}
        shape={shape}
      />
    </Dialog>
  );
}

export function ImageFramingEditor({
  imageUrl,
  shape,
  initial,
  onCancel,
  onConfirm,
}: {
  imageUrl: string;
  shape: FramingShape;
  initial: ImageFraming;
  onCancel: () => void;
  onConfirm: (framing: ImageFraming) => void;
}) {
  const [framing, setFraming] = useState<ImageFraming>(initial);
  const frameRef = useRef<HTMLDivElement | null>(null);
  const imageRef = useRef<HTMLImageElement | null>(null);
  // The drag is computed from where it started, so small moves are not lost to rounding.
  const dragRef = useRef<{ x: number; y: number; from: ImageFraming } | null>(null);

  function onPointerDown(e: PointerEvent<HTMLDivElement>) {
    e.currentTarget.setPointerCapture(e.pointerId);
    dragRef.current = { x: e.clientX, y: e.clientY, from: framing };
  }

  function onPointerMove(e: PointerEvent<HTMLDivElement>) {
    const drag = dragRef.current;
    const frame = frameRef.current;
    const image = imageRef.current;
    if (!drag || !frame || !image) return;
    const box = frame.getBoundingClientRect();
    setFraming(
      dragFraming(
        drag.from,
        e.clientX - drag.x,
        e.clientY - drag.y,
        { width: box.width, height: box.height },
        { width: image.naturalWidth, height: image.naturalHeight },
      ),
    );
  }

  function endDrag() {
    dragRef.current = null;
  }

  return (
    <>
      <DialogBody>
        <div
          aria-label="Zone de cadrage : fais glisser l'image, ou déplace-la avec les flèches du clavier."
          className={cn(
            FRAME_CLASS[shape],
            "relative cursor-grab touch-none overflow-hidden border border-border bg-surface-2 active:cursor-grabbing focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
          )}
          onKeyDown={(e) => {
            const next = nudgeFraming(framing, e.key);
            if (next) {
              e.preventDefault();
              setFraming(next);
            }
          }}
          onPointerCancel={endDrag}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={endDrag}
          ref={frameRef}
          role="group"
          tabIndex={0}
        >
          {/* eslint-disable-next-line @next/next/no-img-element -- presigned storage URL, possibly a GIF */}
          <img
            alt=""
            className="pointer-events-none absolute inset-0 size-full select-none object-cover"
            draggable={false}
            ref={imageRef}
            src={imageUrl}
            style={framingStyle(framing)}
          />
        </div>
        <label className="grid gap-1.5 text-sm">
          <span className="flex items-center justify-between font-medium text-foreground">
            Zoom
            <span className="text-xs font-semibold text-muted-foreground">{framing.zoom} %</span>
          </span>
          <input
            className="w-full accent-accent"
            max={MAX_ZOOM}
            min={MIN_ZOOM}
            onChange={(e) => setFraming(zoomFraming(framing, Number(e.target.value)))}
            step={5}
            type="range"
            value={framing.zoom}
          />
        </label>
        {shape === "banner" ? (
          <p className="text-xs text-muted-foreground">
            La bannière s&apos;adapte à la largeur de l&apos;écran : garde le sujet près du point choisi.
          </p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost", className: "mr-auto" })} onClick={() => setFraming(CENTRED_FRAMING)} type="button">
          <RotateCcw aria-hidden className="size-4" /> Recentrer
        </button>
        <button className={buttonVariants({ variant: "secondary" })} onClick={onCancel} type="button">
          Annuler
        </button>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => onConfirm(framing)} type="button">
          Valider
        </button>
      </DialogFooter>
    </>
  );
}
