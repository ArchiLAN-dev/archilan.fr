/**
 * Length limits for the authored content fields (story 10.10).
 *
 * These four fields were unbounded TEXT with only a `trim()` applied; markdown makes longer input
 * more attractive, so they get a cap. Values are deliberately generous - the goal is to stop an
 * unbounded payload, not to constrain legitimate authoring - and each one is mirrored server-side
 * (an API-only or frontend-only limit would be either bypassable or a lying counter).
 *
 * The already-capped fields keep their existing 2000: profile bio, profile comments
 * (both rejected server-side) and install-step descriptions (truncated by InstallStepsNormalizer).
 */
export const EVENT_DESCRIPTION_MAX = 5000;
export const GAME_DESCRIPTION_MAX = 5000;
export const ACHIEVEMENT_DESCRIPTION_MAX = 1000;
export const CONTRIBUTION_MESSAGE_MAX = 2000;

/**
 * Already enforced server-side by `InstallStepsNormalizer::MAX_DESCRIPTION`, but by *silent
 * truncation* - mirroring it in the editor turns that into a visible stop instead of losing text.
 */
export const INSTALL_STEP_DESCRIPTION_MAX = 2000;
