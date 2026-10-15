import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { FilterTabs } from '@/components/common/filter-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    attentionReasons,
    type ClientFilter,
    type ClientHealth,
    type ClientOverviewRow,
    countByHealth,
    filterClients,
    formatNextPost,
    HEALTH_LABEL,
} from '@/lib/clients/overview';
import { cn } from '@/lib/utils';
import { switchWorkspace } from '@/lib/workspaces/switch-workspace';

type Props = {
    clients: ClientOverviewRow[];
};

const HEALTH_BADGE: Record<
    ClientHealth,
    'destructive' | 'warning' | 'success' | 'secondary'
> = {
    attention: 'destructive',
    empty: 'warning',
    no_accounts: 'secondary',
    ok: 'success',
};

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((word) => word[0]?.toUpperCase())
            .join('') || '?'
    );
}

function ClientRow({ client }: { client: ClientOverviewRow }) {
    const [opening, setOpening] = useState(false);
    const reasons = attentionReasons(client);
    const next = formatNextPost(client);

    return (
        <li className="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <span
                    className={cn(
                        'grid size-10 shrink-0 place-items-center rounded-full bg-muted text-sm font-semibold text-muted-foreground',
                        client.health === 'attention' &&
                            'bg-destructive/10 text-destructive',
                    )}
                    aria-hidden="true"
                >
                    {initials(client.name)}
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="truncate text-[15px] font-semibold">
                            {client.name}
                        </h2>
                        <Badge variant={HEALTH_BADGE[client.health]}>
                            {HEALTH_LABEL[client.health]}
                        </Badge>
                        {client.is_current && (
                            <Badge variant="outline">Current</Badge>
                        )}
                    </div>

                    {reasons.length > 0 && (
                        <p className="mt-1 text-[13px] font-medium text-destructive">
                            {reasons.join(' · ')}
                        </p>
                    )}

                    <dl className="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-muted-foreground">
                        <div>
                            <dt className="sr-only">Accounts</dt>
                            <dd>
                                {client.accounts.total === 0
                                    ? 'No accounts connected'
                                    : `${client.accounts.active} of ${client.accounts.total} accounts connected`}
                            </dd>
                        </div>
                        <div>
                            <dt className="sr-only">Next post</dt>
                            <dd>
                                {next
                                    ? `Next post ${next}`
                                    : 'Nothing scheduled'}
                            </dd>
                        </div>
                        {client.posts.next_7_days > 0 && (
                            <div>
                                <dt className="sr-only">This week</dt>
                                <dd>
                                    {client.posts.next_7_days} scheduled this
                                    week
                                </dd>
                            </div>
                        )}
                        <div>
                            <dt className="sr-only">Published</dt>
                            <dd>
                                {client.posts.published_7_days} published in the
                                last 7 days
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <Button
                variant={client.is_current ? 'outline' : 'default'}
                size="sm"
                className="self-start sm:self-center"
                disabled={opening}
                aria-label={`Open ${client.name}`}
                onClick={() => {
                    setOpening(true);
                    switchWorkspace(client.id, {
                        onFinish: () => setOpening(false),
                    });
                }}
            >
                {opening ? 'Opening…' : 'Open'}
            </Button>
        </li>
    );
}

export default function ClientsIndex({ clients }: Props) {
    const [filter, setFilter] = useState<ClientFilter>('all');
    const counts = countByHealth(clients);
    const visible = filterClients(clients, filter);

    return (
        <>
            <Head title="Clients" />

            <div className="mx-auto w-full max-w-5xl space-y-5 px-4 pt-6 pb-16 sm:px-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-[22px] leading-tight font-semibold tracking-tight">
                        Clients
                    </h1>
                    <p className="text-[13px] text-muted-foreground">
                        Every workspace you manage, with what needs your
                        attention first.
                    </p>
                </div>

                <FilterTabs
                    value={filter}
                    onChange={(value) => setFilter(value as ClientFilter)}
                    tabs={[
                        { value: 'all', label: 'All', count: clients.length },
                        {
                            value: 'attention',
                            label: 'Needs attention',
                            count: counts.attention,
                        },
                        {
                            value: 'empty',
                            label: 'Nothing this week',
                            count: counts.empty,
                        },
                    ]}
                />

                {visible.length === 0 ? (
                    <Card className="items-center py-12 text-center">
                        <p className="text-sm font-medium">
                            {filter === 'all'
                                ? 'No clients yet'
                                : 'Nothing here — all clear.'}
                        </p>
                    </Card>
                ) : (
                    <Card size="sm" className="gap-0 py-0">
                        <ul className="divide-y divide-border">
                            {visible.map((client) => (
                                <ClientRow key={client.id} client={client} />
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [{ title: 'Clients', href: '/clients' }],
};
