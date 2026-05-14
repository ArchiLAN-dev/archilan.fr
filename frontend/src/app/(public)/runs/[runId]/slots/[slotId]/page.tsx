import type { Metadata } from "next";
import { PersonalRunSlotYamlPage } from "@/features/personal-runs/personal-run-slot-yaml-page";

export const metadata: Metadata = {
  title: "Configuration YAML",
  description: "Configurer les options YAML d'un slot de ta partie personnelle.",
};

export default function RunSlotYamlPage({ params }: { params: Promise<{ runId: string; slotId: string }> }) {
  return <PersonalRunSlotYamlPage params={params} />;
}
