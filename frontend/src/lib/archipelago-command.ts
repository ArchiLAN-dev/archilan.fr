/**
 * Archipelago server commands sent through the bridge (`!admin /...`).
 *
 * The server splits most commands with Python's `shlex`: an argument with a space must be quoted to
 * stay one argument, and an unbalanced apostrophe drops the whole line back to a plain split. A few
 * commands take the rest of the line as is (`mark_raw` server-side: release, collect, allow_release,
 * forbid_release, alias): their argument must NOT be quoted, the quotes would become part of the name.
 */

const SHLEX_SAFE = /^[\w@%+=:,./-]+$/;

/** One argument quoted as `shlex.quote` does, so the server reads it back unchanged. */
export function quoteApArg(value: string): string {
  if (value !== "" && SHLEX_SAFE.test(value)) return value;
  return `'${value.replace(/'/g, `'"'"'`)}'`;
}

/** `!admin /<command> <args...>`, every argument quoted, for the commands the server splits. */
export function adminCommand(command: string, ...args: (string | number)[]): string {
  return [`!admin /${command}`, ...args.map((arg) => quoteApArg(String(arg)))].join(" ");
}

/** `!admin /<command> <rest>` for a command that reads the rest of the line as is (never quoted). */
export function rawAdminCommand(command: string, rest: string): string {
  return `!admin /${command} ${rest}`;
}
