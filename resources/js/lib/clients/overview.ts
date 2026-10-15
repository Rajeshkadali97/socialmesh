export type ClientHealth = 'attention' | 'empty' | 'no_accounts' | 'ok';

export type ClientOverviewRow = {
    id: string;
    name: string;
    role: 'owner' | 'admin' | 'member';
    timezone: string;
    is_current: boolean;
    health: ClientHealth;
    accounts: {
        total: number;
        active: number;
        needs_attention: number;
        disabled: number;
    };
    posts: {
        scheduled: number;
        next_scheduled_at: string | null;
        next_7_days: number;
        problems: number;
        published_7_days: number;
    };
    queue_slots: number;
};

export type ClientFilter = 'all' | 'attention' | 'empty';

export const HEALTH_LABEL: Record<ClientHealth, string> = {
    attention: 'Needs attention',
    empty: 'Nothing this week',
    no_accounts: 'No accounts',
    ok: 'On track',
};

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** Clients that match a filter tab. */
export function filterClients(
    clients: ClientOverviewRow[],
    filter: ClientFilter,
): ClientOverviewRow[] {
    if (filter === 'all') {
        return clients;
    }

    return clients.filter((client) => client.health === filter);
}

export function countByHealth(
    clients: ClientOverviewRow[],
): Record<ClientHealth, number> {
    const counts: Record<ClientHealth, number> = {
        attention: 0,
        empty: 0,
        no_accounts: 0,
        ok: 0,
    };

    for (const client of clients) {
        counts[client.health] += 1;
    }

    return counts;
}

/**
 * The plain-language reasons a client needs attention, most urgent first, so
 * the row can say what is wrong instead of just that something is.
 */
export function attentionReasons(client: ClientOverviewRow): string[] {
    const reasons: string[] = [];

    if (client.accounts.needs_attention > 0) {
        reasons.push(
            `${plural(client.accounts.needs_attention, 'account')} to reconnect`,
        );
    }

    if (client.posts.problems > 0) {
        reasons.push(
            `${plural(client.posts.problems, 'post')} failed or missed recently`,
        );
    }

    return reasons;
}

/** When the next post goes out, in the client's own timezone. */
export function formatNextPost(
    client: ClientOverviewRow,
    locale?: string,
): string | null {
    if (!client.posts.next_scheduled_at) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: client.timezone,
    }).format(new Date(client.posts.next_scheduled_at));
}
