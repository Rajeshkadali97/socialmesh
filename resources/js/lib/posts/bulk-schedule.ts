export type BulkRow = {
    line: number;
    account: string;
    account_id: string | null;
    platform: string | null;
    handle: string | null;
    scheduled_at: string | null;
    text: string;
    image_url: string | null;
    format: 'post' | 'story';
    errors: string[];
};

export type BulkSummary = { valid: number; invalid: number };

export function summarizeRows(rows: BulkRow[]): BulkSummary {
    const valid = rows.filter((row) => row.errors.length === 0).length;

    return { valid, invalid: rows.length - valid };
}

export function scheduleLabel(valid: number): string {
    return valid === 1 ? 'Schedule 1 post' : `Schedule ${valid} posts`;
}

/** Shows an ISO instant as a short date/time in the client's own timezone. */
export function formatWhen(iso: string | null, timezone: string): string {
    if (iso === null) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone,
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}
