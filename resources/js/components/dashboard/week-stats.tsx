import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import { index as calendarRoute } from '@/routes/calendar';
import { index as postsRoute } from '@/routes/posts';

export type WeekStatsData = {
    scheduled_next_7_days: number;
    published_last_7_days: number;
    drafts: number;
    needs_attention: number;
};

type Stat = {
    label: string;
    value: number;
    href: string;
    tone: string;
};

/** The big-number strip at the top of the dashboard: this client's week at a glance. */
export function WeekStats({ stats }: { stats: WeekStatsData }) {
    const items: Stat[] = [
        {
            label: 'Scheduled · next 7 days',
            value: stats.scheduled_next_7_days,
            href: calendarRoute().url,
            tone: 'text-brand-green',
        },
        {
            label: 'Published · last 7 days',
            value: stats.published_last_7_days,
            href: postsRoute().url,
            tone: 'text-foreground',
        },
        {
            label: 'Drafts',
            value: stats.drafts,
            href: postsRoute().url,
            tone: 'text-foreground',
        },
        {
            label: 'Needs attention',
            value: stats.needs_attention,
            href: accountsRoute().url,
            tone:
                stats.needs_attention > 0
                    ? 'text-destructive'
                    : 'text-muted-foreground',
        },
    ];

    return (
        <ul className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {items.map((item) => (
                <li key={item.label}>
                    <Link
                        href={item.href}
                        className="block rounded-3xl bg-card px-5 py-4 shadow-[0_10px_30px_-14px_rgb(20_70_70/0.18)] ring-1 ring-foreground/5 transition hover:-translate-y-0.5"
                    >
                        <span
                            className={cn(
                                'block text-3xl font-semibold tracking-tight tabular-nums',
                                item.tone,
                            )}
                        >
                            {item.value}
                        </span>
                        <span className="mt-1 block text-xs text-muted-foreground">
                            {item.label}
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
