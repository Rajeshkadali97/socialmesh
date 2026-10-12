import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { xsrfHeader } from '@/lib/csrf';
import { dayjs } from '@/lib/datetime/dayjs';
import type { ShareDecision, ShareReview } from '@/types/share';

type Props = { token: string; initial: ShareReview };

/**
 * Client-facing approve / request-changes card on the public share page. No
 * account needed — the share token authorises it.
 */
export function ShareReviewPanel({ token, initial }: Props) {
    const [review, setReview] = useState(initial);
    const [name, setName] = useState(initial.reviewer_name ?? '');
    const [note, setNote] = useState('');
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!review.can_review) {
        return null;
    }

    async function submit(decision: ShareDecision) {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(`/share/${token}/decision`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...xsrfHeader(),
                },
                body: JSON.stringify({
                    decision,
                    note: decision === 'changes_requested' ? note : null,
                    reviewer_name: name || null,
                }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                setError(
                    body.errors?.note?.[0] ??
                        body.message ??
                        'Could not save your answer. Please try again.',
                );

                return;
            }
            setReview({ ...review, ...body });
            setAsking(false);
            setNote('');
        } catch {
            setError('Could not save your answer. Please try again.');
        } finally {
            setBusy(false);
        }
    }

    const decided = review.decision !== null;

    return (
        <section
            aria-label="Review this post"
            className="mt-8 rounded-3xl bg-card p-5 shadow-[0_10px_30px_-14px_rgb(20_70_70/0.18)] ring-1 ring-foreground/5 sm:p-6"
        >
            {decided && (
                <p
                    role="status"
                    className={
                        review.decision === 'approved'
                            ? 'mb-4 rounded-2xl bg-success/10 px-4 py-3 text-sm font-medium text-success'
                            : 'mb-4 rounded-2xl bg-brand-orange/10 px-4 py-3 text-sm font-medium text-foreground'
                    }
                >
                    {review.decision === 'approved'
                        ? 'You approved this post'
                        : 'You asked for changes'}
                    {review.decided_at &&
                        ` · ${dayjs(review.decided_at).format('MMM D, h:mm A')}`}
                    {review.decision === 'changes_requested' && review.note && (
                        <span className="mt-1 block font-normal text-muted-foreground">
                            “{review.note}”
                        </span>
                    )}
                </p>
            )}

            <h2 className="text-base font-semibold">
                {decided ? 'Changed your mind?' : 'Is this good to go?'}
            </h2>
            <p className="mt-1 text-[13px] text-muted-foreground">
                Your answer goes straight back to the team. Nothing is published
                until they schedule it.
            </p>

            <label className="mt-4 block text-[12px] font-medium text-muted-foreground">
                Your name (optional)
                <input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    maxLength={80}
                    className="mt-1 block h-9 w-full rounded-xl border border-input bg-background px-3 text-sm text-foreground"
                />
            </label>

            {asking && (
                <Textarea
                    aria-label="What should change?"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={3}
                    maxLength={1000}
                    placeholder="What should we change?"
                    className="mt-3"
                    autoFocus
                />
            )}
            {error && (
                <p role="alert" className="mt-2 text-sm text-destructive">
                    {error}
                </p>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {!asking ? (
                    <>
                        <Button
                            disabled={busy}
                            onClick={() => void submit('approved')}
                        >
                            Approve
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setAsking(true)}
                        >
                            Request changes
                        </Button>
                    </>
                ) : (
                    <>
                        <Button
                            disabled={busy || note.trim() === ''}
                            onClick={() => void submit('changes_requested')}
                        >
                            Send feedback
                        </Button>
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => setAsking(false)}
                        >
                            Cancel
                        </Button>
                    </>
                )}
            </div>
        </section>
    );
}
