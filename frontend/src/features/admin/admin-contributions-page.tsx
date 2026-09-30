"use client";

import { ContributionsModerationPanel } from "./contributions-moderation-panel";
import { ModerationPage, useModerationParams } from "./moderation-page";

/** `/admin/moderation/contributions` (story 39.13). */
export function AdminContributionsPage() {
  const [params, onParams] = useModerationParams();

  return (
    <ModerationPage description="Tutoriels d'installation proposés par les membres, à approuver ou refuser." title="Contributions tutoriels">
      <ContributionsModerationPanel onParams={onParams} params={params} />
    </ModerationPage>
  );
}
