import { describe, expect, it } from 'vitest';

import {
    attentionReasons,
    countByHealth,
    filterClients,
    formatNextPost,
    type ClientOverviewRow,
} from '../overview';

function client(overrides: Partial<ClientOverviewRow> = {}): ClientOverviewRow {
    return {
        id: 'w1',
        name: 'Acme',
        role: 'owner',
        timezone: 'UTC',
        is_current: false,
        health: 'ok',
        accounts: { total: 2, active: 2, needs_attention: 0, disabled: 0 },
        posts: {
            scheduled: 0,
            next_scheduled_at: null,
            next_7_days: 0,
            problems: 0,
            published_7_days: 0,
        },
        queue_slots: 0,
        ...overrides,
    };
}

describe('filterClients', () => {
    const rows = [
        client({ id: 'a', health: 'attention' }),
        client({ id: 'b', health: 'empty' }),
        client({ id: 'c', health: 'ok' }),
    ];

    it('returns every client for "all"', () => {
        expect(filterClients(rows, 'all')).toHaveLength(3);
    });

    it('narrows to one health state', () => {
        expect(filterClients(rows, 'attention').map((c) => c.id)).toEqual([
            'a',
        ]);
        expect(filterClients(rows, 'empty').map((c) => c.id)).toEqual(['b']);
    });
});

describe('countByHealth', () => {
    it('tallies each health state', () => {
        const counts = countByHealth([
            client({ health: 'attention' }),
            client({ health: 'attention' }),
            client({ health: 'no_accounts' }),
        ]);

        expect(counts).toEqual({
            attention: 2,
            empty: 0,
            no_accounts: 1,
            ok: 0,
        });
    });
});

describe('attentionReasons', () => {
    it('says nothing for a healthy client', () => {
        expect(attentionReasons(client())).toEqual([]);
    });

    it('names each problem with correct pluralisation', () => {
        expect(
            attentionReasons(
                client({
                    accounts: {
                        total: 3,
                        active: 2,
                        needs_attention: 1,
                        disabled: 0,
                    },
                    posts: {
                        scheduled: 0,
                        next_scheduled_at: null,
                        next_7_days: 0,
                        problems: 2,
                        published_7_days: 0,
                    },
                }),
            ),
        ).toEqual([
            '1 account to reconnect',
            '2 posts failed or missed recently',
        ]);
    });
});

describe('formatNextPost', () => {
    it('returns null when nothing is scheduled', () => {
        expect(formatNextPost(client())).toBeNull();
    });

    it("formats the time in the client's timezone", () => {
        const row = client({
            timezone: 'Asia/Kolkata',
            posts: {
                scheduled: 1,
                next_scheduled_at: '2026-10-15T09:00:00+00:00',
                next_7_days: 1,
                problems: 0,
                published_7_days: 0,
            },
        });

        // 09:00 UTC is 14:30 in India.
        expect(formatNextPost(row, 'en-US')).toContain('2:30 PM');
    });
});
