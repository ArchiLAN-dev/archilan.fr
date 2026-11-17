import { parse, printParseErrorCode, type ParseError } from "jsonc-parser";

import { PackError } from "./errors";

/**
 * Pack JSON as PopTracker reads it (story 54.4): comments, trailing commas and a leading BOM are accepted.
 *
 * @throws PackError json_invalid
 */
export function parseJsonc(text: string, file?: string): unknown {
  const errors: ParseError[] = [];
  const value: unknown = parse(text.replace(/^﻿/, ""), errors, { allowTrailingComma: true, disallowComments: false });
  const first = errors[0];
  if (first !== undefined) {
    const line = text.slice(0, first.offset).split("\n").length;
    throw new PackError("json_invalid", `JSON invalide (${printParseErrorCode(first.error)}) ligne ${line}`, file, line);
  }
  return value;
}
