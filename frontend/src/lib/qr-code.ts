import { create as createQrCode } from "qrcode";

/**
 * The dark modules of a QR code as one SVG path, a unit square each (story 43.3; the blind test's QR code of story
 * 41.45 too). Drawn here, without a network call nor an image: the code never leaves the page.
 */
export function qrPath(value: string): { size: number; d: string } {
  const { size, data } = createQrCode(value, { errorCorrectionLevel: "M" }).modules;
  let d = "";
  for (let row = 0; row < size; row++) {
    for (let col = 0; col < size; col++) {
      if (data[row * size + col] === 1) d += `M${col} ${row}h1v1h-1z`;
    }
  }
  return { size, d };
}
