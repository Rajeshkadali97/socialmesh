import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

import { FilterTabs } from '@/components/common/filter-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Archive,
    ExternalLink,
    Film,
    Image,
    Inbox,
    Paperclip,
    Search,
    Trash2,
    X,
} from '@/components/ui/icons';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';

export type MediaLibraryReferencedPost = {
    id: string;
    excerpt: string;
    status: string;
    scheduled_at: string | null;
};

export type MediaLibraryItem = {
    id: string;
    url: string;
    mime: string;
    kind: 'image' | 'video';
    duration_seconds: number | null;
    alt_text: string | null;
    original_filename: string | null;
    size_bytes: number;
    lifecycle_state: string;
    drive_file_id: string | null;
    checksum: string | null;
    campaign: string | null;
    storage_error: string | null;
    created_at: string | null;
    archived_at: string | null;
    disk: string;
    reference_count: number;
    referenced_posts: MediaLibraryReferencedPost[];
};

type Props = {
    items: MediaLibraryItem[];
    campaigns: string[];
    counts: {
        all: number;
        image: number;
        video: number;
        archived: number;
    };
    filters: {
        kind: string;
        state: string;
        campaign: string;
        q: string;
    };
};

function formatBytes(bytes: number): string {
    if (bytes <= 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const val = bytes / 1024 ** i;
    return `${val >= 10 || i === 0 ? Math.round(val) : val.toFixed(1)} ${units[i]}`;
}

function stateBadgeLabel(state: string): string {
    switch (state) {
        case 'attached_to_draft':
            return 'In draft';
        case 'attached_to_scheduled_post':
            return 'Scheduled';
        case 'used_in_published_post':
            return 'Published';
        case 'archived':
            return 'Archived';
        case 'uploading':
            return 'Uploading';
        case 'processing':
            return 'Processing';
        case 'failed':
            return 'Storage error';
        default:
            return 'Available';
    }
}

export default function MediaLibraryIndex({
    items,
    campaigns,
    counts,
    filters,
}: Props) {
    const fileInput = useRef<HTMLInputElement | null>(null);
    const [search, setSearch] = useState(filters.q);
    const [uploading, setUploading] = useState(false);
    const [selectedItem, setSelectedItem] = useState<MediaLibraryItem | null>(
        null,
    );

    const activeTab =
        filters.state === 'archived'
            ? 'archived'
            : filters.kind === 'image' || filters.kind === 'video'
              ? filters.kind
              : 'all';

    function applyFilter(next: Partial<Props['filters']>) {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = {};
        if (merged.kind && merged.kind !== 'all') {
            query.kind = merged.kind;
        }
        if (merged.state && merged.state !== 'active') {
            query.state = merged.state;
        }
        if (merged.campaign) {
            query.campaign = merged.campaign;
        }
        if (merged.q) {
            query.q = merged.q;
        }
        router.get('/media', query, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    async function handleUploadFiles(fileList: FileList | null) {
        if (!fileList || fileList.length === 0) {
            return;
        }
        setUploading(true);
        const xsrf = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        try {
            for (const file of Array.from(fileList)) {
                if (file.type.startsWith('video/')) {
                    const signRes = await fetch('/media/video-url', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            ...(xsrf
                                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                                : {}),
                        },
                        body: JSON.stringify({ content_type: 'video/mp4' }),
                    });
                    if (!signRes.ok) {
                        throw new Error('Could not initialize video upload.');
                    }
                    const signed = (await signRes.json()) as {
                        key: string;
                        url: string;
                        headers: Record<string, string>;
                    };
                    const putRes = await fetch(signed.url, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'video/mp4',
                            ...signed.headers,
                            ...(xsrf
                                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                                : {}),
                        },
                        body: file,
                    });
                    if (!putRes.ok) {
                        throw new Error('Video stream upload failed.');
                    }
                    const confirmRes = await fetch('/media/video', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            ...(xsrf
                                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                                : {}),
                        },
                        body: JSON.stringify({
                            key: signed.key,
                            width: 1280,
                            height: 720,
                            duration_seconds: 10,
                            alt_text: null,
                        }),
                    });
                    if (!confirmRes.ok) {
                        throw new Error('Video verification failed.');
                    }
                } else {
                    const form = new FormData();
                    form.append('file', file);
                    if (filters.campaign) {
                        form.append('campaign', filters.campaign);
                    }
                    const res = await fetch('/media', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            ...(xsrf
                                ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                                : {}),
                        },
                        body: form,
                    });
                    if (!res.ok) {
                        const err = (await res
                            .json()
                            .catch(() => ({}))) as Record<string, string>;
                        throw new Error(err.message || 'Image upload failed.');
                    }
                }
            }
            toast.success('Uploaded to Ebin Cloud media library.');
            router.reload();
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Upload failed.');
        } finally {
            setUploading(false);
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    }

    function handleArchive(item: MediaLibraryItem) {
        router.post(
            `/media/${item.id}/archive`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (selectedItem?.id === item.id) {
                        setSelectedItem(null);
                    }
                },
            },
        );
    }

    function handleDelete(item: MediaLibraryItem) {
        router.delete(`/media/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (selectedItem?.id === item.id) {
                    setSelectedItem(null);
                }
            },
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Compose', href: dashboard().url },
                { title: 'Media Library', href: '/media' },
            ]}
        >
            <Head title="Media Library" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight text-foreground">
                            Media Library
                        </h1>
                        <p className="text-xs text-muted-foreground">
                            Reusable images and videos backed by Ebin Cloud
                            storage.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/*,video/mp4"
                            multiple
                            hidden
                            onChange={(e) =>
                                void handleUploadFiles(e.target.files)
                            }
                        />
                        <Button
                            size="sm"
                            disabled={uploading}
                            onClick={() => fileInput.current?.click()}
                        >
                            <Paperclip className="mr-1.5 size-3.5" />
                            {uploading ? 'Uploading…' : 'Upload media'}
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                    <FilterTabs
                        value={activeTab}
                        onChange={(val: string) => {
                            if (val === 'archived') {
                                applyFilter({ kind: 'all', state: 'archived' });
                            } else {
                                applyFilter({ kind: val, state: 'active' });
                            }
                        }}
                        tabs={[
                            {
                                value: 'all',
                                label: 'All',
                                count: counts.all,
                            },
                            {
                                value: 'image',
                                label: 'Images',
                                count: counts.image,
                            },
                            {
                                value: 'video',
                                label: 'Videos',
                                count: counts.video,
                            },
                            {
                                value: 'archived',
                                label: 'Archived',
                                count: counts.archived,
                            },
                        ]}
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        {campaigns.length > 0 && (
                            <select
                                aria-label="Filter by campaign"
                                value={filters.campaign}
                                onChange={(e) =>
                                    applyFilter({ campaign: e.target.value })
                                }
                                className="h-8 rounded-md border border-border bg-background px-2.5 text-xs text-foreground"
                            >
                                <option value="">All campaigns</option>
                                {campaigns.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                        )}

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilter({ q: search });
                            }}
                            className="relative flex items-center"
                        >
                            <Search className="pointer-events-none absolute left-2.5 size-3.5 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search filename or campaign…"
                                className="h-8 w-52 pr-7 pl-8 text-xs"
                            />
                            {search !== '' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        applyFilter({ q: '' });
                                    }}
                                    className="absolute right-2 text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </form>
                    </div>
                </div>

                {items.length === 0 ? (
                    <Empty className="my-8">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Inbox />
                            </EmptyMedia>
                            <EmptyTitle>No media assets found</EmptyTitle>
                            <EmptyDescription>
                                Upload images or videos to store them
                                persistently in Ebin Cloud and reuse them across
                                posts.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        {items.map((item) => (
                            <div
                                key={item.id}
                                onClick={() => setSelectedItem(item)}
                                className="group relative flex cursor-pointer flex-col overflow-hidden rounded-lg border border-border bg-card transition hover:border-foreground/30"
                            >
                                <div className="relative aspect-square w-full bg-muted">
                                    {item.kind === 'video' ? (
                                        <video
                                            src={item.url}
                                            className="size-full object-cover"
                                            muted
                                            playsInline
                                        />
                                    ) : (
                                        <img
                                            src={item.url}
                                            alt={
                                                item.alt_text ??
                                                item.original_filename ??
                                                'Media'
                                            }
                                            loading="lazy"
                                            className="size-full object-cover"
                                        />
                                    )}
                                    <div className="absolute top-1.5 left-1.5 flex items-center gap-1">
                                        <Badge
                                            variant="secondary"
                                            className="px-1.5 py-0 text-[10px]"
                                        >
                                            {item.kind === 'video' ? (
                                                <Film className="mr-1 size-2.5" />
                                            ) : (
                                                <Image className="mr-1 size-2.5" />
                                            )}
                                            {formatBytes(item.size_bytes)}
                                        </Badge>
                                    </div>
                                    <div className="absolute top-1.5 right-1.5">
                                        <Badge
                                            variant="outline"
                                            className="bg-background/90 px-1.5 py-0 text-[10px]"
                                        >
                                            {stateBadgeLabel(
                                                item.lifecycle_state,
                                            )}
                                        </Badge>
                                    </div>
                                </div>

                                <div className="flex flex-col gap-1 p-2.5">
                                    <div className="truncate text-xs font-medium text-foreground">
                                        {item.original_filename ?? 'Asset'}
                                    </div>
                                    <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                        <span>
                                            {item.reference_count === 1
                                                ? '1 post'
                                                : `${item.reference_count} posts`}
                                        </span>
                                        {item.campaign && (
                                            <span className="max-w-[90px] truncate rounded bg-muted px-1.5 py-0.5 text-[10px]">
                                                {item.campaign}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {selectedItem && (
                    <div className="rounded-lg border border-border bg-card p-4">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-sm font-semibold text-foreground">
                                        {selectedItem.original_filename ??
                                            selectedItem.id}
                                    </h2>
                                    <Badge variant="secondary">
                                        {stateBadgeLabel(
                                            selectedItem.lifecycle_state,
                                        )}
                                    </Badge>
                                    <Badge variant="outline">
                                        Disk: {selectedItem.disk}
                                    </Badge>
                                </div>
                                <p className="font-mono text-[11px] text-muted-foreground">
                                    Size: {formatBytes(selectedItem.size_bytes)}{' '}
                                    | MIME: {selectedItem.mime}
                                    {selectedItem.checksum
                                        ? ` | SHA-256: ${selectedItem.checksum.slice(0, 16)}…`
                                        : ''}
                                    {selectedItem.drive_file_id
                                        ? ` | Cloud ID: ${selectedItem.drive_file_id}`
                                        : ''}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <a
                                    href={selectedItem.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex h-8 items-center gap-1 rounded-md border border-border px-2.5 text-xs hover:bg-muted"
                                >
                                    <ExternalLink className="size-3.5" />
                                    Open raw
                                </a>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleArchive(selectedItem)}
                                >
                                    <Archive className="mr-1.5 size-3.5" />
                                    {selectedItem.archived_at
                                        ? 'Restore'
                                        : 'Archive'}
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => handleDelete(selectedItem)}
                                >
                                    <Trash2 className="mr-1.5 size-3.5" />
                                    Delete
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSelectedItem(null)}
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                        </div>

                        {selectedItem.referenced_posts.length > 0 && (
                            <div className="mt-3 border-t border-border pt-3">
                                <div className="mb-1.5 text-xs font-medium text-muted-foreground">
                                    Referenced by{' '}
                                    {selectedItem.referenced_posts.length}{' '}
                                    post(s):
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {selectedItem.referenced_posts.map((p) => (
                                        <Link
                                            key={p.id}
                                            href={`/posts/${p.id}`}
                                            className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2.5 py-1 text-xs hover:bg-muted"
                                        >
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {p.status}
                                            </Badge>
                                            <span className="max-w-xs truncate">
                                                {p.excerpt || 'Untitled post'}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
