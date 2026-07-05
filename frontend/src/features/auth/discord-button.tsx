import { FaDiscord } from "react-icons/fa";
import { env } from "@/lib/env";

type Props = {
  label: string;
};

export function DiscordButton({ label }: Props) {
  return (
    <a
      className="inline-flex min-h-12 items-center justify-center gap-3 rounded bg-discord px-5 font-semibold text-white transition-colors hover:bg-discord-hover"
      href={`${env.apiBaseUrl}/auth/discord`}
    >
      <FaDiscord aria-hidden="true" size={20} />
      {label}
    </a>
  );
}
