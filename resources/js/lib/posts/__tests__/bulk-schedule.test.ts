import { describe, expect, it } from 'vitest';

import {
    type BulkRow,
    formatWhen,
    scheduleLabel,
    summarizeRows,
} from '../bulk-schedule';

const row = (errors: string[]): BulkRow => ({
    line: 2,
    account: '@brand',
    account_id: 'a',
    platform: 'x',
    handle: '@brand',
    scheduled_at: '2030-01-01T09:00:00+00:00',
    text: 'Hi',
    image_url: null,
    format: 'post',
    errors,
});

describe('bulk schedule helpers', () => {
    it('counts valid and invalid rows', () => {
        expect(summarizeRows([row([]), row(['bad']), row([])])).toEqual({
            valid: 2,
            invalid: 1,
        });
    });

    it('pluralises the confirm label', () => {
        expect(scheduleLabel(1)).toBe('Schedule 1 post');
        expect(scheduleLabel(12)).toBe('Schedule 12 posts');
    });

    it('formats an instant in the client timezone', () => {
        expect(
            formatWhen('2030-01-01T03:30:00+00:00', 'Asia/Kolkata'),
        ).toContain('09:00');
        expect(formatWhen(null, 'UTC')).toBe('—');
    });
});
