import type { ReactNode } from "react";

import styles from "./holo-name.module.css";

/** The holographic look of a member's name (story 30.44), from the API: gold admin, silver member. */
export type NameStyle = "gold" | "silver";

export function isNameStyle(value: unknown): value is NameStyle {
  return value === "gold" || value === "silver";
}

/**
 * A member's name, holographic when their status gives it (story 30.44): gold for an admin, silver for a member,
 * like the title of a trading card. On the profile page the reflection sweeps all the time; on a card
 * (`onHover`) only while the card is hovered. Without a style, the name is rendered as is.
 */
export function HoloName({
  children,
  style,
  onHover = false,
  className,
}: {
  children: ReactNode;
  style: NameStyle | null | undefined;
  onHover?: boolean;
  className?: string;
}) {
  if (!isNameStyle(style)) {
    return className ? <span className={className}>{children}</span> : <>{children}</>;
  }

  const classes = [styles.holo, styles[style], onHover ? styles.onHover : null, className].filter(Boolean).join(" ");
  return (
    <span className={classes} data-name-style={style}>
      {children}
    </span>
  );
}
