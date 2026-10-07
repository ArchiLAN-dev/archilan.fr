import type { ReactNode } from "react";

import { TITLE_RARITY_LABELS, titleOrigin, type TitleBadge, type TitleIcon } from "./profile-title-catalog";
import styles from "./profile-title-badge.module.css";

/** Story 41.27: the icons a title may wear, drawn as strokes in the rarity colour. */
const ICON_PATHS: Record<TitleIcon, ReactNode> = {
  crown: (
    <>
      <path d="M2 18l2-11 5 5 3-8 3 8 5-5 2 11z" />
      <path d="M4 21h16" />
    </>
  ),
  star: <path d="M12 2l3 7 7 .6-5.3 4.6 1.6 7L12 17.5 5.7 21.2l1.6-7L2 9.6 9 9z" />,
  sword: (
    <>
      <path d="M14.5 17.5L3 6V3h3l11.5 11.5" />
      <path d="M13 19l6-6" />
      <path d="M16 16l4 4" />
      <path d="M19 21l2-2" />
    </>
  ),
  gem: (
    <>
      <path d="M6 3h12l4 6-10 12L2 9z" />
      <path d="M2 9h20" />
      <path d="M12 21L8 9l4-6 4 6z" />
    </>
  ),
  shield: <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z" />,
  flame: <path d="M12 2c1 4 6 6 6 12a6 6 0 0 1-12 0c0-3 2-5 3-6 0 2 1 3 2 3 0-4-1-6 1-9z" />,
  trophy: (
    <>
      <path d="M8 21h8" />
      <path d="M12 17v4" />
      <path d="M7 4h10v5a5 5 0 0 1-10 0z" />
      <path d="M17 5h3v2a3 3 0 0 1-3 3" />
      <path d="M7 5H4v2a3 3 0 0 0 3 3" />
    </>
  ),
  bolt: <path d="M13 2L4 14h7l-1 8 9-12h-7z" />,
};

export function TitleIconGlyph({ icon, className = "" }: { icon: TitleIcon; className?: string }) {
  return (
    <svg aria-hidden className={className} fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} viewBox="0 0 24 24">
      {ICON_PATHS[icon]}
    </svg>
  );
}

type Props = {
  title: TitleBadge;
  /** `profile` under the name on the profile, `card` beside a name on a card. */
  variant?: "profile" | "card";
  /** Story 41.27: where the title comes from, on hover and keyboard focus (the profile). */
  withTooltip?: boolean;
  className?: string;
};

/**
 * Story 41.22: a title worn under the name. Story 41.27: drawn by its rarity, with its icon - the same badge on the
 * profile, the cards, the shop and the editors.
 */
export function ProfileTitleBadge({ title, variant = "profile", withTooltip = false, className = "" }: Props) {
  const badge = (
    <span className={`${styles.badge} ${styles[variant]} ${styles[title.rarity]} ${withTooltip ? "" : className}`} data-rarity={title.rarity}>
      {title.icon ? <TitleIconGlyph className={styles.icon} icon={title.icon} /> : null}
      <span className={styles.label}>{title.label}</span>
    </span>
  );
  if (!withTooltip) return badge;

  const rarity = `Titre ${TITLE_RARITY_LABELS[title.rarity].toLowerCase()}`;
  // Story 41.28: the achievement, the quest or the shop it came from, else who may wear it.
  const origin = title.origin ?? titleOrigin(title.access);

  return (
    <span aria-label={`${title.label} : ${rarity.toLowerCase()}, ${origin.toLowerCase()}`} className={`${styles.withTip} ${className}`} role="note" tabIndex={0}>
      {badge}
      <span aria-hidden className={styles.tip}>
        <span className={`${styles.tipTitle} ${styles[`tip${title.rarity}`]}`}>{rarity}</span>
        <span>{origin}</span>
      </span>
    </span>
  );
}
