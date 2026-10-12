<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Throwable;

/**
 * Turns a pasted/uploaded CSV (or TSV) into scheduled posts for one workspace.
 *
 * Columns: account, date, time, text, image_url (optional), format (optional).
 * Dates are YYYY-MM-DD and times HH:MM (24h), read in the workspace timezone.
 * Every row is validated independently so one bad row never blocks the rest.
 *
 * @phpstan-type BulkRow array{line: int, account: string, account_id: ?string, platform: ?string, handle: ?string, scheduled_at: ?string, text: string, image_url: ?string, format: string, errors: list<string>}
 */
class BulkScheduleImporter
{
    public const MAX_ROWS = 50;

    public const TEMPLATE = "account,date,time,text,image_url,format\n@brand,2026-12-01,09:00,\"Launch day! Link in bio.\",https://example.com/launch.jpg,post\n";

    public function __construct(
        private readonly DraftService $drafts,
        private readonly MediaStorageService $media,
        private readonly PublishPrecheck $precheck,
    ) {}

    /**
     * @return array{rows: list<BulkRow>, error: ?string}
     */
    public function preview(Workspace $workspace, string $input): array
    {
        $table = $this->parse($input);
        if ($table['error'] !== null) {
            return ['rows' => [], 'error' => $table['error']];
        }

        $accounts = ConnectedAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->enabled()
            ->get();

        $zone = in_array($workspace->timezone, timezone_identifiers_list(), true) ? $workspace->timezone : 'UTC';
        $now = CarbonImmutable::now('UTC');
        $seen = [];
        $rows = [];

        foreach ($table['records'] as $line => $record) {
            $row = $this->buildRow($line, $record, $accounts, $zone, $now);

            if ($row['errors'] === [] && $row['account_id'] !== null && $row['scheduled_at'] !== null) {
                $key = $row['account_id'].'|'.$row['scheduled_at'].'|'.$row['text'];
                if (isset($seen[$key]) || $this->alreadyScheduled($workspace->id, $row)) {
                    $row['errors'][] = 'Duplicate — the same post is already scheduled for this time.';
                }
                $seen[$key] = true;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Create and schedule every valid row. Failures are reported per row.
     *
     * @return array{scheduled: list<array{line: int, post_id: string}>, failed: list<array{line: int, message: string}>, error: ?string}
     */
    public function commit(Workspace $workspace, User $author, string $input): array
    {
        $preview = $this->preview($workspace, $input);
        $scheduled = [];
        $failed = [];

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                $failed[] = ['line' => $row['line'], 'message' => $row['errors'][0]];

                continue;
            }

            try {
                $scheduled[] = ['line' => $row['line'], 'post_id' => $this->schedule($workspace, $author, $row)];
            } catch (Throwable $e) {
                $failed[] = ['line' => $row['line'], 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not schedule this row.'];
            }
        }

        return ['scheduled' => $scheduled, 'failed' => $failed, 'error' => $preview['error']];
    }

    /**
     * @param  BulkRow  $row
     */
    private function schedule(Workspace $workspace, User $author, array $row): string
    {
        $accountId = (string) $row['account_id'];
        $format = $row['format'] === 'story' ? PostFormat::Story : PostFormat::Feed;
        $text = $format->allowsCaption() ? $row['text'] : '';
        $destination = ['kind' => 'account', 'id' => $accountId];

        $post = $this->drafts->createDraft($workspace->id, $author, $destination, [$text]);

        try {
            $mediaIds = [];
            if ($row['image_url'] !== null) {
                $mediaIds[] = $this->media->storeFromUrl($workspace->id, $row['image_url'])->id;
            }

            $post = $this->drafts->updateDraft($post, DraftData::fromArray([
                'segments' => [$text],
                'destination' => $destination,
                'media_ids' => $mediaIds,
                'targets' => [['connected_account_id' => $accountId, 'format' => $format->value]],
            ]));

            $blocking = $this->precheck->blockingTargets($post->load(['targets.account', 'media']));
            if ($blocking !== []) {
                $first = $blocking[0];

                throw new \RuntimeException($this->precheck->describe($first['issues'], Platform::from($first['platform'])));
            }

            $post->forceFill([
                'scheduled_at' => $row['scheduled_at'],
                'status' => PostStatus::Scheduled,
            ])->save();
        } catch (Throwable $e) {
            $post->forceFill(['status' => PostStatus::Deleted])->save();

            throw $e;
        }

        return (string) $post->id;
    }

    /**
     * @param  array<string, string>  $record
     * @param  iterable<ConnectedAccount>  $accounts
     * @return BulkRow
     */
    private function buildRow(int $line, array $record, iterable $accounts, string $zone, CarbonImmutable $now): array
    {
        $errors = [];
        $accountInput = trim($record['account'] ?? '');
        $text = trim($record['text'] ?? '');
        $imageUrl = trim($record['image_url'] ?? '');
        $formatInput = strtolower(trim($record['format'] ?? ''));

        $account = null;
        if ($accountInput === '') {
            $errors[] = 'Missing account.';
        } else {
            $matches = $this->matchAccounts($accounts, $accountInput);
            if (count($matches) === 0) {
                $errors[] = "No connected account matches \"{$accountInput}\" in this client.";
            } elseif (count($matches) > 1) {
                $errors[] = "\"{$accountInput}\" matches several accounts — write it as platform:handle (e.g. instagram:@brand).";
            } else {
                $account = $matches[0];
            }
        }

        $format = 'post';
        if ($formatInput === 'story' || $formatInput === 'stories') {
            $format = 'story';
        } elseif (in_array($formatInput, ['reel', 'reels'], true)) {
            $errors[] = 'Reels need a video, which bulk import cannot fetch yet — schedule Reels from the composer.';
        } elseif (! in_array($formatInput, ['', 'post', 'feed'], true)) {
            $errors[] = "Unknown format \"{$formatInput}\" (use post or story).";
        }

        $scheduledAt = $this->parseWhen($record['date'] ?? '', $record['time'] ?? '', $zone, $now, $errors);

        if ($imageUrl !== '' && ! preg_match('#^https://#i', $imageUrl)) {
            $errors[] = 'image_url must be a public https:// link.';
        }
        if ($imageUrl !== '' && preg_match('/\.(mp4|mov|m4v|webm)(\?|$)/i', $imageUrl) === 1) {
            $errors[] = 'Videos cannot be imported by link yet — use an image.';
        }

        if ($account instanceof ConnectedAccount && $errors === []) {
            $platform = $account->platform;
            if ($format === 'story') {
                $text = '';
                if ($imageUrl === '') {
                    $errors[] = 'Stories need an image_url.';
                }
            } else {
                if ($text === '' && $imageUrl === '') {
                    $errors[] = 'Add text or an image.';
                }
                if ($platform->requiresMedia() && $imageUrl === '') {
                    $errors[] = $platform->label().' posts need an image_url.';
                }
                if (mb_strlen($text) > $platform->maxLength() && $platform->threadMax() === null) {
                    $errors[] = $platform->label().' allows at most '.$platform->maxLength().' characters; this text has '.mb_strlen($text).'.';
                }
            }
        }

        return [
            'line' => $line,
            'account' => $accountInput,
            'account_id' => $account instanceof ConnectedAccount ? (string) $account->id : null,
            'platform' => $account instanceof ConnectedAccount ? $account->platform->value : null,
            'handle' => $account?->handle,
            'scheduled_at' => $scheduledAt?->setTimezone('UTC')->toIso8601String(),
            'text' => $text,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'format' => $format,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseWhen(string $date, string $time, string $zone, CarbonImmutable $now, array &$errors): ?CarbonImmutable
    {
        $date = trim($date);
        $time = trim($time);
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $zone);
        } catch (InvalidFormatException) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== "{$date} {$time}") {
            $errors[] = 'Use date as YYYY-MM-DD and time as HH:MM (24-hour), e.g. 2026-12-01 and 09:30.';

            return null;
        }

        if ($parsed->lessThanOrEqualTo($now)) {
            $errors[] = 'That time has already passed ('.$zone.').';
        }

        return $parsed;
    }

    /**
     * @param  iterable<ConnectedAccount>  $accounts
     * @return list<ConnectedAccount>
     */
    private function matchAccounts(iterable $accounts, string $input): array
    {
        $platformHint = null;
        $needle = $input;
        if (str_contains($input, ':') && ! str_starts_with($input, '@')) {
            [$platformHint, $needle] = explode(':', $input, 2);
            $platformHint = strtolower(trim($platformHint));
        }
        $needle = ltrim(strtolower(trim($needle)), '@');

        $matches = [];
        foreach ($accounts as $account) {
            if (ltrim(strtolower($account->handle), '@') !== $needle) {
                continue;
            }
            if ($platformHint !== null && $account->platform->value !== $platformHint) {
                continue;
            }
            $matches[] = $account;
        }

        return $matches;
    }

    /**
     * @param  BulkRow  $row
     */
    private function alreadyScheduled(string $workspaceId, array $row): bool
    {
        return Post::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', PostStatus::Scheduled->value)
            ->where('scheduled_at', CarbonImmutable::parse((string) $row['scheduled_at'])->utc())
            ->where('base_text', $row['text'])
            ->whereHas('targets', fn ($query) => $query->where('connected_account_id', $row['account_id']))
            ->exists();
    }

    /**
     * @return array{records: array<int, array<string, string>>, error: ?string}
     */
    private function parse(string $input): array
    {
        $input = ltrim($input, "\xEF\xBB\xBF");
        if (trim($input) === '') {
            return ['records' => [], 'error' => 'Paste or upload a CSV first.'];
        }

        $firstLine = strtok($input, "\n") ?: '';
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ['records' => [], 'error' => 'Could not read the file.'];
        }
        fwrite($stream, $input);
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '');
        $columns = is_array($header) ? array_map(fn ($c): string => strtolower(trim((string) $c)), $header) : [];
        foreach (['account', 'date', 'time', 'text'] as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($stream);

                return ['records' => [], 'error' => "The first row must be a header with the column \"{$required}\" (columns: account, date, time, text, image_url, format)."];
            }
        }

        $records = [];
        $line = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(fn ($c): string => (string) $c, $cells)) === '') {
                continue;
            }
            $record = [];
            foreach ($columns as $i => $name) {
                $record[$name] = (string) ($cells[$i] ?? '');
            }
            $records[$line] = $record;

            if (count($records) > self::MAX_ROWS) {
                fclose($stream);

                return ['records' => [], 'error' => 'Import at most '.self::MAX_ROWS.' posts at a time.'];
            }
        }
        fclose($stream);

        if ($records === []) {
            return ['records' => [], 'error' => 'The file has a header but no rows.'];
        }

        return ['records' => $records, 'error' => null];
    }
}
