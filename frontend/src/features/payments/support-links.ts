/** The « Soutenir ArchiLAN » tab of the shop (story 41.13). */
export const SUPPORT_TAB_HREF = "/boutique?onglet=asso";

/**
 * The anchor of the joining section. Never `adhesion`: EasyList (the default list of uBlock Origin, AdBlock Plus and
 * Brave) hides every `#adhesion` element on every site (`###adhesion`), which made the whole section vanish
 * (story 41.63).
 */
export const MEMBERSHIP_ANCHOR = "adherer";

/** Where a « Adhérer » link leads: the shop's support tab, scrolled to the joining section. */
export const MEMBERSHIP_HREF = `${SUPPORT_TAB_HREF}#${MEMBERSHIP_ANCHOR}`;
