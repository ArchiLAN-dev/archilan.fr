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
    <svg aria-hidden="true" className={styles.emblem} viewBox="0 0 24 24">
      {style === "legendary" ? (
        <path d="M3 18h18l-1.5-10-4.5 4-3-7-3 7-4.5-4z" />
      ) : (
        <path d="M12 2l2.9 6.6 7.1.7-5.4 4.7 1.6 7-6.2-3.7L5.8 21l1.6-7L2 9.3l7.1-.7z" />
      )}
    </svg>
  );
}

/**
 * A member's name with the title their status gives (story 30.44): legendary orange for an admin, silver for
 * a member (the epic tier).
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
