import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Check, Copy, Mail } from '@/components/ui/icons';
import { Input } from '@/components/ui/input';

export default function InvitationLinkReveal({ link }: { link: string }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (!navigator.clipboard) {
            toast.error(
                'Copy is unavailable here — select the link and copy it manually.',
            );
            return;
        }
        try {
            await navigator.clipboard.writeText(link);
            setCopied(true);
            toast.success('Invitation link copied');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Copy failed — select the link and copy it manually.');
        }
    }

    return (
        <Card className="border-primary/40 bg-primary/5">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Mail className="size-4 text-primary" />
                    Invitation link
                </CardTitle>
                <CardDescription>
                    Send this link to the invitee if the email does not arrive.
                    It is shown once; cancel and re-invite to get a new link.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex items-center gap-2">
                <Input
                    readOnly
                    value={link}
                    aria-label="Invitation link"
                    onFocus={(event) => event.target.select()}
                    className="font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    className="shrink-0"
                    onClick={copy}
                >
                    {copied ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </CardContent>
        </Card>
    );
}
