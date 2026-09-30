import type { ReactNode } from "react";

import styles from "./titled-name.module.css";

/** A member's titled name (story 30.44), from the API: legendary for an admin, epic for a member. */
export type NameStyle = "legendary" | "epic";

export function isNameStyle(value: unknown): value is NameStyle {
  return value === "legendary" || value === "epic";
}

const TITLES: Record<NameStyle, string> = {
  legendary: "Administrateur",
  epic: "Adhérent ArchiLAN",
};

function Emblem({ style }: { style: NameStyle }) {
  return (
    <span aria-hidden="true" className={styles.emblem} data-emblem={style === "legendary" ? "crown" : "star"}>
      <span className={`${styles.emblemShape} ${style === "legendary" ? styles.crown : styles.star}`} />
    </span>
  );
}

/**
 * A member's name with the title their status gives (story 30.44): legendary orange for an admin, platinum
 * for a member (the epic tier).
 *
 * - `profile`: the title stands large above the name with its emblem and embers rise from the name. The title
 *   is hidden from screen readers: the profile badges already say it.
 * - `card`: the emblem before the name, which glows more on hover; the card keeps its height.
 *
 * Without a style, the name is rendered as is.
 */
export function TitledName({
  children,
  style,
  variant,
}: {
  children: ReactNode;
  style: NameStyle | null | undefined;
  variant: "profile" | "card";
}) {
  if (!isNameStyle(style)) {
    return <>{children}</>;
  }

  if (variant === "card") {
    return (
      <span className={`${styles.card} ${styles[style]}`} data-name-style={style}>
        <Emblem style={style} />
        <span className={styles.name}>{children}</span>
      </span>
    );
  }

  return (
    <span className={`${styles.profile} ${styles[style]}`} data-name-style={style}>
      <span aria-hidden="true" className={styles.title}>
        <Emblem style={style} />
        {TITLES[style]}
      </span>
      <span className={styles.nameLine}>
        <span className={styles.name}>{children}</span>
        <span aria-hidden="true" className={styles.embers}>
          <i />
          <i />
          <i />
          <i />
          <i />
          <i />
        </span>
      </span>
    </span>
  );
}
