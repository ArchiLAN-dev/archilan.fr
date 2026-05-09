export const TWITCH_CONSENT_KEY = "archilan_twitch_consent";
export const TWITCH_CONSENT_GRANTED = "granted";
export const TWITCH_CONSENT_REJECTED = "rejected";
export const TWITCH_CONSENT_EVENT = "archilan:twitch-consent";

export type TwitchConsentDetail = { granted: boolean };

export function setTwitchConsent(granted: boolean): void {
  localStorage.setItem(
    TWITCH_CONSENT_KEY,
    granted ? TWITCH_CONSENT_GRANTED : TWITCH_CONSENT_REJECTED,
  );
  window.dispatchEvent(
    new CustomEvent<TwitchConsentDetail>(TWITCH_CONSENT_EVENT, { detail: { granted } }),
  );
}
