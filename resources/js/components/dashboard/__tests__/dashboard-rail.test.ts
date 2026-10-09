import { describe, expect, it } from 'vitest';

import { buildAttentionItems } from '../dashboard-rail';

describe('buildAttentionItems', () => {
    it('returns nothing when everything is caught up', () => {
        expect(
            buildAttentionItems({
                accounts: [{ status: 'active' }],
                unreadReplies: 0,
                unreadMessages: 0,
            }),
        ).toEqual([]);
    });

    it('lists only the inboxes that have something waiting', () => {
        const items = buildAttentionItems({
            accounts: [{ status: 'active' }],
            unreadReplies: 3,
            unreadMessages: 0,
        });

        expect(items.map((item) => [item.key, item.count])).toEqual([
            ['replies', 3],
        ]);
    });

    it('counts accounts that need reconnecting', () => {
        const items = buildAttentionItems({
            accounts: [
                { status: 'needs_attention' },
                { status: 'active' },
                { status: 'needs_attention' },
            ],
            unreadReplies: 0,
            unreadMessages: 2,
        });

        expect(items.map((item) => [item.key, item.count])).toEqual([
            ['messages', 2],
            ['reconnect', 2],
        ]);
    });

    it('hides inboxes the instance has switched off', () => {
        const items = buildAttentionItems({
            accounts: [],
            unreadReplies: 5,
            unreadMessages: 4,
            engagementEnabled: false,
            messagesEnabled: false,
        });

        expect(items).toEqual([]);
    });
});
