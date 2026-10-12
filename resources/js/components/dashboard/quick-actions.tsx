import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    CalendarDays,
    LayoutGrid,
    ListChecks,
    Rocket,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { index as calendarRoute } from '@/routes/calendar';
import { index as clientsRoute } from '@/routes/clients';

type Tile = {
    label: string;
    hint: string;
    href: string;
    icon: ReactNode;
    tone: string;
};

/**
 * Four shortcut tiles above the composer — the jobs an agency repeats all day.
 * Pure navigation, so they cost no data and render with the page.
 */
export function QuickActions({ multiClient }: { multiClient: boolean }) {
    const tiles: Tile[] = [
        {
            label: 'Bulk schedule',
            hint: 'Paste a spreadsheet',
            href: '/posts/bulk',
            icon: <Rocket className="size-5" />,
            tone: 'bg-brand-orange/15 text-brand-orange',
        },
        {
            label: 'Calendar',
            hint: 'See the month',
            href: calendarRoute().url,
            icon: <CalendarDays className="size-5" />,
            tone: 'bg-brand-peach text-primary',
        },
        {
            label: 'Queue',
            hint: 'Weekly time slots',
            href: '/queue',
            icon: <ListChecks className="size-5" />,
            tone: 'bg-primary/10 text-primary',
        },
        ...(multiClient
            ? [
                  {
                      label: 'All clients',
                      hint: 'Switch in one click',
                      href: clientsRoute().url,
                      icon: <LayoutGrid className="size-5" />,
                      tone: 'bg-brand-peach text-primary',
                  },
              ]
            : []),
    ];

    return (
        <ul className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {tiles.map((tile) => (
                <li key={tile.label}>
                    <Link
                        href={tile.href}
                        className="group flex h-full flex-col gap-3 rounded-3xl bg-card p-4 shadow-[0_10px_30px_-14px_rgb(20_70_70/0.18)] ring-1 ring-foreground/5 transition hover:-translate-y-0.5 hover:shadow-[0_16px_34px_-14px_rgb(20_70_70/0.28)]"
                    >
                        <span
                            className={cn(
                                'grid size-10 place-items-center rounded-2xl',
                                tile.tone,
                            )}
                        >
                            {tile.icon}
                        </span>
                        <span>
                            <span className="block text-sm font-semibold">
                                {tile.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {tile.hint}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
