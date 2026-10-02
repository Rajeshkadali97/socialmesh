import { Link, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Heart } from '@/components/ui/icons';

export function SidebarFooterCard() {
    const { features, billing, community } = usePage().props;

    if (features?.billing) {
        // Only surface the upgrade nudge for unsubscribed workspaces. A subscribed
        // workspace already manages billing via the Subscription item in the sidebar
        // nav, so an "Active" chip would just waste footer space.
        if (!billing || billing.subscribed) {
            return null;
        }

        return (
            <div className="rounded-md border border-sidebar-border p-2 group-data-[collapsible=icon]:hidden">
                <p className="text-xs font-medium text-sidebar-foreground">
                    SocialMesh Cloud
                </p>
                <p className="text-[11px] text-sidebar-foreground/60">
                    Free plan
                </p>
                <Button
                    size="sm"
                    className="mt-2 w-full"
                    render={<Link href={billing.manageUrl} />}
                >
                    Upgrade
                </Button>
            </div>
        );
    }

    if (!community?.sponsorUrl) {
        return null;
    }

    return (
        <div className="flex flex-col gap-0.5 group-data-[collapsible=icon]:hidden">
            <a
                href={community.sponsorUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
            >
                <Heart className="h-4 w-4" aria-hidden="true" />
                <span>Sponsor</span>
            </a>
        </div>
    );
}
