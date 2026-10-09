import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { AccountAvatar } from '@/components/common/account-avatar';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Inbox, MessageCircle, TriangleAlert } from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import { index as engagementRoute } from '@/routes/engagement';
import { index as messagesRoute } from '@/routes/messages';
import type { Account } from '@/types/compose';

const VISIBLE_ACCOUNTS = 6;

export type AttentionKey = 'replies' | 'messages' | 'reconnect';

export type AttentionItem = {
    key: AttentionKey;
    label: string;
    count: number;
    href: string;
};

const ATTENTION_ICONS: Record<AttentionKey, ReactNode> = {
    replies: <Inbox className="size-4" />,
    messages: <MessageCircle className="size-4" />,
    reconnect: <TriangleAlert className="size-4" />,
};

/**
 * The rows the "Needs your attention" card shows: only inboxes the instance has
 * enabled, and only those with something waiting.
 */
export function buildAttentionItems({
    accounts,
    unreadReplies,
    unreadMessages,
    engagementEnabled = true,
    messagesEnabled = true,
}: {
    accounts: Pick<Account, 'status'>[];
    unreadReplies: number;
    unreadMessages: number;
    engagementEnabled?: boolean;
    messagesEnabled?: boolean;
}): AttentionItem[] {
    const needsReconnect = accounts.filter(
        (account) => account.status === 'needs_attention',
    ).length;

    const items: AttentionItem[] = [
        ...(engagementEnabled
            ? [
                  {
                      key: 'replies' as const,
                      label: 'Unread replies',
                      count: unreadReplies,
                      href: engagementRoute().url,
                  },
              ]
            : []),
        ...(messagesEnabled
            ? [
                  {
                      key: 'messages' as const,
                      label: 'Unread messages',
                      count: unreadMessages,
                      href: messagesRoute().url,
                  },
              ]
            : []),
        {
            key: 'reconnect',
            label: 'Accounts to reconnect',
            count: needsReconnect,
            href: accountsRoute().url,
        },
    ];

    return items.filter((item) => item.count > 0);
}

type Props = {
    accounts: Account[];
    unreadReplies: number;
    unreadMessages: number;
    /** Hide the inbox rows for features the instance has switched off. */
    engagementEnabled?: boolean;
    messagesEnabled?: boolean;
};

/**
 * Right-hand rail on the dashboard: what needs a response right now, and the
 * connected accounts at a glance. Everything comes from shared page props, so it
 * renders instantly with the page and costs no extra request.
 */
export function DashboardRail({
    accounts,
    unreadReplies,
    unreadMessages,
    engagementEnabled = true,
    messagesEnabled = true,
}: Props) {
    const pending = buildAttentionItems({
        accounts,
        unreadReplies,
        unreadMessages,
        engagementEnabled,
        messagesEnabled,
    });
    const shownAccounts = accounts.slice(0, VISIBLE_ACCOUNTS);
    const hiddenAccounts = accounts.length - shownAccounts.length;

    return (
        <aside className="hidden space-y-5 xl:block" aria-label="Overview">
            <Card size="sm">
                <CardHeader>
                    <CardTitle>Needs your attention</CardTitle>
                </CardHeader>
                <CardContent>
                    {pending.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            You&apos;re all caught up.
                        </p>
                    ) : (
                        <ul className="-mx-2 space-y-0.5">
                            {pending.map((item) => (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        className="flex items-center gap-3 rounded-xl px-2 py-2 text-sm transition-colors hover:bg-muted"
                                    >
                                        <span
                                            className={cn(
                                                'grid size-8 shrink-0 place-items-center rounded-full bg-primary/10 text-primary',
                                                item.key === 'reconnect' &&
                                                    'bg-destructive/10 text-destructive',
                                            )}
                                        >
                                            {ATTENTION_ICONS[item.key]}
                                        </span>
                                        <span className="flex-1 font-medium">
                                            {item.label}
                                        </span>
                                        <span className="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground tabular-nums">
                                            {item.count}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Card size="sm">
                <CardHeader className="grid-cols-[1fr_auto]">
                    <CardTitle>Your accounts</CardTitle>
                    <Link
                        href={accountsRoute().url}
                        className="text-xs font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Manage
                    </Link>
                </CardHeader>
                <CardContent>
                    {accounts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No accounts connected yet.
                        </p>
                    ) : (
                        <ul className="-mx-2 space-y-0.5">
                            {shownAccounts.map((account) => (
                                <li
                                    key={account.id}
                                    className="flex items-center gap-3 rounded-xl px-2 py-1.5"
                                >
                                    <AccountAvatar
                                        platform={account.platform}
                                        handle={account.handle}
                                        avatarUrl={account.avatar_url}
                                        size="md"
                                        ringClassName="ring-card"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {account.display_name ??
                                                account.handle}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {account.handle}
                                        </span>
                                    </span>
                                    <span
                                        role="img"
                                        aria-label={
                                            account.status === 'needs_attention'
                                                ? 'Needs reconnecting'
                                                : 'Connected'
                                        }
                                        className={cn(
                                            'size-2 shrink-0 rounded-full',
                                            account.status === 'needs_attention'
                                                ? 'bg-destructive'
                                                : 'bg-success',
                                        )}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    {hiddenAccounts > 0 && (
                        <Link
                            href={accountsRoute().url}
                            className="mt-2 block text-xs text-muted-foreground hover:text-foreground"
                        >
                            +{hiddenAccounts} more
                        </Link>
                    )}
                </CardContent>
            </Card>
        </aside>
    );
}
