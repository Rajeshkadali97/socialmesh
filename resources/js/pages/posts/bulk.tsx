import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { xsrfHeader } from '@/lib/csrf';
import {
    type BulkRow,
    formatWhen,
    scheduleLabel,
    summarizeRows,
} from '@/lib/posts/bulk-schedule';
import { index as postsRoute } from '@/routes/posts';

type Props = { template: string; maxRows: number; timezone: string };

type Result = {
    scheduled: { line: number; post_id: string }[];
    failed: { line: number; message: string }[];
};

async function post<T>(url: string, csv: string): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...xsrfHeader(),
        },
        body: JSON.stringify({ csv }),
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(
            body.message ?? 'Something went wrong. Please try again.',
        );
    }

    return body as T;
}

export default function BulkSchedule({ template, maxRows, timezone }: Props) {
    const [csv, setCsv] = useState('');
    const [rows, setRows] = useState<BulkRow[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Result | null>(null);

    const summary = rows ? summarizeRows(rows) : null;

    async function check() {
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const data = await post<{ rows: BulkRow[]; error: string | null }>(
                '/posts/bulk/preview',
                csv,
            );
            setRows(data.error ? null : data.rows);
            setError(data.error);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }

    async function confirm() {
        setBusy(true);
        setError(null);
        try {
            const data = await post<Result>('/posts/bulk', csv);
            setResult(data);
            setRows(null);
            if (data.failed.length === 0) {
                setCsv('');
            }
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }

    async function loadFile(file: File | undefined) {
        if (file) {
            setCsv(await file.text());
            setRows(null);
            setResult(null);
        }
    }

    const templateHref = `data:text/csv;charset=utf-8,${encodeURIComponent(template)}`;

    return (
        <>
            <Head title="Bulk schedule" />

            <div className="mx-auto w-full max-w-5xl space-y-5 px-4 pt-6 pb-16 sm:px-6">
                <div className="space-y-1">
                    <h1 className="text-[22px] leading-tight font-semibold tracking-tight">
                        Bulk schedule
                    </h1>
                    <p className="text-[13px] text-muted-foreground">
                        Paste a spreadsheet to schedule up to {maxRows} posts
                        for this client at once. Times are in{' '}
                        <span className="font-medium text-foreground">
                            {timezone}
                        </span>
                        .
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>1. Add your rows</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Columns: <code>account</code>, <code>date</code>{' '}
                            (YYYY-MM-DD), <code>time</code> (HH:MM),{' '}
                            <code>text</code>, and optionally{' '}
                            <code>image_url</code> (https link) and{' '}
                            <code>format</code> (post or story). Copy rows
                            straight from Google Sheets or Excel.
                        </p>
                        <Textarea
                            aria-label="Spreadsheet rows"
                            value={csv}
                            onChange={(e) => {
                                setCsv(e.target.value);
                                setRows(null);
                                setResult(null);
                            }}
                            rows={8}
                            className="font-mono text-xs"
                            placeholder={template}
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                onClick={check}
                                disabled={busy || csv.trim() === ''}
                            >
                                {busy && !rows ? 'Checking…' : 'Check rows'}
                            </Button>
                            <label className="inline-flex h-9 cursor-pointer items-center rounded-full border border-border px-4 text-sm font-medium hover:bg-muted">
                                Upload .csv
                                <input
                                    type="file"
                                    accept=".csv,.tsv,.txt,text/csv"
                                    className="sr-only"
                                    onChange={(e) =>
                                        void loadFile(e.target.files?.[0])
                                    }
                                />
                            </label>
                            <a
                                href={templateHref}
                                download="socialmesh-bulk-template.csv"
                                className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                            >
                                Download template
                            </a>
                        </div>
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                    </CardContent>
                </Card>

                {rows && summary && (
                    <Card>
                        <CardHeader>
                            <CardTitle>2. Review</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm">
                                <span className="font-medium">
                                    {summary.valid} ready
                                </span>
                                {summary.invalid > 0 && (
                                    <span className="text-destructive">
                                        {' '}
                                        · {summary.invalid} need fixing (they
                                        will be skipped)
                                    </span>
                                )}
                            </p>
                            <ul className="divide-y divide-border rounded-2xl border border-border">
                                {rows.map((row) => (
                                    <li
                                        key={row.line}
                                        className="flex flex-col gap-1 px-4 py-3 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                Row {row.line}
                                            </span>
                                            <span className="font-medium">
                                                {row.handle ?? row.account}
                                            </span>
                                            {row.platform && (
                                                <Badge variant="secondary">
                                                    {row.platform}
                                                </Badge>
                                            )}
                                            {row.format === 'story' && (
                                                <Badge variant="secondary">
                                                    story
                                                </Badge>
                                            )}
                                            <span className="text-muted-foreground">
                                                {formatWhen(
                                                    row.scheduled_at,
                                                    timezone,
                                                )}
                                            </span>
                                            <span
                                                className={
                                                    row.errors.length === 0
                                                        ? 'ml-auto text-xs font-medium text-success'
                                                        : 'ml-auto text-xs font-medium text-destructive'
                                                }
                                            >
                                                {row.errors.length === 0
                                                    ? 'Ready'
                                                    : 'Skipped'}
                                            </span>
                                        </div>
                                        {row.text && (
                                            <p className="line-clamp-2 text-muted-foreground">
                                                {row.text}
                                            </p>
                                        )}
                                        {row.errors.map((message) => (
                                            <p
                                                key={message}
                                                className="text-xs text-destructive"
                                            >
                                                {message}
                                            </p>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                            <Button
                                onClick={confirm}
                                disabled={busy || summary.valid === 0}
                            >
                                {busy
                                    ? 'Scheduling…'
                                    : scheduleLabel(summary.valid)}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {result && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Done</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p role="status" className="font-medium">
                                {result.scheduled.length} scheduled
                                {result.failed.length > 0 &&
                                    `, ${result.failed.length} skipped`}
                                .
                            </p>
                            {result.failed.map((item) => (
                                <p
                                    key={item.line}
                                    className="text-xs text-destructive"
                                >
                                    Row {item.line}: {item.message}
                                </p>
                            ))}
                            <Link
                                href={postsRoute().url}
                                className="font-medium text-primary underline-offset-4 hover:underline"
                            >
                                View scheduled posts
                            </Link>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

BulkSchedule.layout = {
    breadcrumbs: [{ title: 'Bulk schedule', href: '/posts/bulk' }],
};
