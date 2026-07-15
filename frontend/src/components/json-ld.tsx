import { serializeJsonLd } from "@/lib/structured-data";

type JsonLdProps = {
  data: Record<string, unknown>;
};

/**
 * Renders a schema.org JSON-LD `<script>` with the shared escaped serializer, so `<`, `>`
 * and `&` in the data can never break out of the script context. Use this for every
 * structured-data block - no hand-rolled script tags.
 */
export function JsonLd({ data }: JsonLdProps) {
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: serializeJsonLd(data) }}
    />
  );
}
