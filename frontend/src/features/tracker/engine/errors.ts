/** A failure of the pack itself, carrying what the report of story 54.8 needs (story 54.4). */
export class PackError extends Error {
  constructor(
    readonly kind: string,
    message: string,
    readonly file?: string,
    readonly line?: number,
  ) {
    super(message);
    this.name = "PackError";
  }
}
