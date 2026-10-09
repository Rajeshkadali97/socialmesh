import type { PropsWithChildren } from 'react';

import Heading from '@/components/common/heading';

export default function InstanceSettingsLayout({
    children,
}: PropsWithChildren) {
    return (
        <div className="mx-auto w-full max-w-4xl px-4 pt-6 pb-16 sm:px-6">
            <Heading
                title="Instance settings"
                description="Manage settings that affect every user on this self-hosted instance"
            />

            <section className="min-w-0 space-y-12 rounded-3xl bg-card p-6 shadow-sm ring-1 ring-foreground/5 sm:p-8 dark:ring-foreground/10">
                {children}
            </section>
        </div>
    );
}
