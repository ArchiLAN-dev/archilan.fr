type Props = {
  // The image the verdict was produced on; null for a verdict older than the tracking.
  image: string | null;
  imageId?: string | null;
  // Null when unknown (runner unreachable, or the same tag without both ids): then nothing is claimed.
  onCurrentImage: boolean | null;
  runtimeImage: string | null;
  runtimeImageId?: string | null;
};

// "sha256:" plus 12 hex characters: enough to tell two builds apart, as `docker images` does.
const SHORT_ID_LENGTH = 12;

function shortId(id: string): string {
  return id.replace(/^sha256:/, "").slice(0, SHORT_ID_LENGTH);
}

/**
 * Story 38.8: which Archipelago image the apworld was tested on, and whether that is still the one
 * in use. A verdict from an older image may no longer hold: the image changed under the apworld.
 */
export function ApworldPreflightImage({ image, imageId = null, onCurrentImage, runtimeImage, runtimeImageId = null }: Props) {
  // Same tag, another build (a rebuilt `latest`, a re-pushed tag): the reference alone would read the
  // same twice, so the ids tell what changed (story 38.8 review).
  const rebuilt = onCurrentImage === false && image !== null && image === runtimeImage && imageId !== null && runtimeImageId !== null;

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
      {rebuilt ? (
        <p className="text-warning">
          L&apos;image <span className="font-mono">{image}</span> a été reconstruite depuis (
          <span className="font-mono">{shortId(imageId)}</span> → <span className="font-mono">{shortId(runtimeImageId)}</span>) :
          relance le test pour le vérifier sur la nouvelle.
        </p>
      ) : onCurrentImage === false && runtimeImage !== null ? (
        <p className="text-warning">
          Ce n&apos;est plus l&apos;image en service (<span className="font-mono">{runtimeImage}</span>) : relance le
          test pour le vérifier sur celle-ci.
        </p>
      ) : null}
    </div>
  );
}
