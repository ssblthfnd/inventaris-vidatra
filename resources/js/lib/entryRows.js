/**
 * Shared plumbing for the multiple-entry forms (Tahap 6.9 R10 — `AssetEntry`,
 * `RoomEntry`): stable row ids, the row-list wording, and mapping the server's
 * `items.N.field` validation errors back onto rows.
 */

/** The most rows one request may carry (StoreAssetEntriesRequest / StoreRoomEntriesRequest::MAX_ITEMS). */
export const MAX_ENTRY_ROWS = 100;

let fallbackSeq = 0;

/**
 * A stable client-side row id. `crypto.randomUUID()` only exists in a secure
 * context (HTTPS or localhost), so a plain-HTTP deployment gets a counter-based id.
 */
export function makeClientId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  fallbackSeq += 1;
  return `row-${Date.now()}-${fallbackSeq}`;
}

/** 1-based row numbers -> "Baris 2", "Baris 2 dan 5", "Baris 1, 2, dan 5". */
export function rowList(numbers) {
  if (numbers.length === 1) return `Baris ${numbers[0]}`;
  if (numbers.length === 2) return `Baris ${numbers[0]} dan ${numbers[1]}`;
  return `Baris ${numbers.slice(0, -1).join(', ')}, dan ${numbers[numbers.length - 1]}`;
}

/**
 * Map a 422 `errors` bag onto the rows that were submitted.
 *
 * `items.N.field` belongs to `submittedRows[N]` (N is the position in the
 * request, which is why the caller passes the exact array it sent). The first
 * message per field is kept; `displayField` may move a field's error onto the
 * input that represents it. Anything else (e.g. `items` itself) is general.
 *
 * @returns {{ byRow: Record<string, Record<string, string>>, rowNumbers: number[], general: string[] }}
 */
export function mapItemErrors(errors, submittedRows, displayField = (field) => field) {
  const byRow = {};
  const general = [];

  for (const [key, messages] of Object.entries(errors ?? {})) {
    const message = Array.isArray(messages) ? messages[0] : String(messages);
    const match = /^items\.(\d+)(?:\.(.+))?$/.exec(key);
    const row = match ? submittedRows[Number(match[1])] : null;

    if (row && match[2]) {
      const field = displayField(match[2]);
      byRow[row.clientId] = { ...(byRow[row.clientId] ?? {}) };
      byRow[row.clientId][field] ??= message;
    } else {
      general.push(message);
    }
  }

  const rowNumbers = submittedRows.map((r, i) => (byRow[r.clientId] ? i + 1 : null)).filter((n) => n !== null);

  return { byRow, rowNumbers, general };
}
