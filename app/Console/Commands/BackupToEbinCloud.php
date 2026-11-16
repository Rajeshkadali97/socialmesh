<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\EbinCloudFilesystemAdapter;
use App\Support\FileStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use PDO;
use Throwable;

#[Description('Create a consistent SQLite snapshot (VACUUM INTO), upload to Ebin Cloud Backups, verify SHA-256, and run an isolated restore test.')]
#[Signature('backup:ebin-cloud {--verify-restore : Download the backup from Ebin Cloud into a temp file and verify SQLite PRAGMA integrity_check}')]
class BackupToEbinCloud extends Command
{
    public function handle(): int
    {
        $ebinDisk = FileStorage::disk('ebin_cloud');
        if (! ($ebinDisk instanceof LaravelFilesystemAdapter) || ! ($ebinDisk->getAdapter() instanceof EbinCloudFilesystemAdapter)) {
            $this->error('The ebin_cloud disk is not registered.');

            return self::FAILURE;
        }

        $client = $ebinDisk->getAdapter()->getClient();
        if (! $client->isConfigured()) {
            $this->error('EBIN_CLOUD_URL and EBIN_CLOUD_SERVICE_KEY must be configured.');

            return self::FAILURE;
        }

        $dbPath = (string) config('database.connections.sqlite.database');
        if ($dbPath === '' || $dbPath === ':memory:' || ! is_file($dbPath)) {
            $this->error("SQLite database file not found at {$dbPath}");

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d-Hi');
        $tmpSqlite = sys_get_temp_dir()."/socialmesh-backup-{$stamp}.sqlite";
        $tmpGz = "{$tmpSqlite}.gz";

        @unlink($tmpSqlite);
        @unlink($tmpGz);

        try {
            $pdo = new PDO('sqlite:'.$dbPath);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('VACUUM INTO '.json_encode($tmpSqlite, JSON_THROW_ON_ERROR));
            unset($pdo);

            $rawBytes = (string) file_get_contents($tmpSqlite);
            $gzBytes = gzencode($rawBytes, 9);
            if ($gzBytes === false) {
                $this->error('Could not gzip SQLite snapshot.');

                return self::FAILURE;
            }

            $sha256 = hash('sha256', $gzBytes);
            $remotePath = "backups/socialmesh-sqlite-{$stamp}.sqlite.gz";

            $uploaded = $client->upload(
                path: $remotePath,
                contents: $gzBytes,
                mimeType: 'application/gzip',
                workspaceId: null,
                category: 'backup',
                idempotencyKey: 'backup-'.$stamp.'-'.$sha256,
                metadata: [
                    'raw_size_bytes' => strlen($rawBytes),
                    'gz_size_bytes' => strlen($gzBytes),
                    'sha256' => $sha256,
                ],
            );

            if ($this->option('verify-restore')) {
                $downloadedGz = $client->download('id:'.$uploaded['id']);
                if (hash('sha256', $downloadedGz) !== $sha256) {
                    $this->error('Downloaded backup SHA-256 did not match.');

                    return self::FAILURE;
                }

                $restoredRaw = gzdecode($downloadedGz);
                if ($restoredRaw === false) {
                    $this->error('Could not decompress downloaded backup.');

                    return self::FAILURE;
                }

                $restoreFile = sys_get_temp_dir()."/socialmesh-restore-verify-{$stamp}.sqlite";
                file_put_contents($restoreFile, $restoredRaw);

                try {
                    $restorePdo = new PDO('sqlite:'.$restoreFile);
                    $integrityStmt = $restorePdo->query('PRAGMA integrity_check');
                    $countStmt = $restorePdo->query('SELECT count(*) FROM workspaces');
                    $integrity = $integrityStmt !== false ? (string) $integrityStmt->fetchColumn() : 'error';
                    $workspaceCount = $countStmt !== false ? (int) $countStmt->fetchColumn() : 0;
                    unset($restorePdo);

                    if ($integrity !== 'ok') {
                        $this->error("Restored SQLite failed integrity_check: {$integrity}");

                        return self::FAILURE;
                    }

                    $this->info("Restore verification passed (PRAGMA integrity_check=ok, workspaces={$workspaceCount}, id={$uploaded['id']}, sha256={$sha256}).");
                } finally {
                    @unlink($restoreFile);
                }
            } else {
                $this->info("Uploaded SQLite backup to Ebin Cloud (id={$uploaded['id']}, sha256={$sha256}).");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup to Ebin Cloud failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpSqlite);
            @unlink($tmpGz);
        }
    }
}
