type Props = {
  // The image the verdict was produced on; null for a verdict older than the tracking.
  image: string | null;
  // Null when the image in use is unknown (runner unreachable): then nothing is claimed.
  onCurrentImage: boolean | null;
  runtimeImage: string | null;
};

/**
 * Story 38.8: which Archipelago image the apworld was tested on, and whether that is still the one
 * in use. A verdict from an older image may no longer hold: the image changed under the apworld.
 */
export function ApworldPreflightImage({ image, onCurrentImage, runtimeImage }: Props) {
  return (
    <div className="grid gap-0.5 text-xs text-muted-foreground">
      <p>
        {image !== null ? (
          <>
            Testé sur <span className="font-mono text-foreground">{image}</span>
          </>
        ) : (
          "Image inconnue : verdict antérieur au suivi des images Archipelago."
        )}
      </p>
      {onCurrentImage === false && runtimeImage !== null ? (
        <p className="text-warning">
          Ce n&apos;est plus l&apos;image en service (<span className="font-mono">{runtimeImage}</span>) : relance le
          test pour le vérifier sur celle-ci.
        </p>
      ) : null}
    </div>
  );
}
