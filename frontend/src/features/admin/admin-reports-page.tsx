"use client";

import { ModerationPage, useModerationParams } from "./moderation-page";
import { ReportsModerationPanel } from "./reports-moderation-panel";

/** `/admin/moderation/signalements` (story 39.13). */
export function AdminReportsPage() {
  const [params, onParams] = useModerationParams();

  return (
    <ModerationPage description="Commentaires et profils signalés par les membres, et comptes au-delà du seuil." title="Signalements">
      <ReportsModerationPanel onParams={onParams} params={params} />
    </ModerationPage>
  );
}
