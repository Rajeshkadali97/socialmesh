<?php

use App\Console\Commands\CaptureMetrics;
use App\Console\Commands\DispatchDueMessageFetches;
use App\Console\Commands\DispatchDuePosts;
use App\Console\Commands\DispatchDueReplyFetches;
use App\Console\Commands\DispatchDueReposts;
use App\Console\Commands\PollNativePosts;
use App\Console\Commands\PruneAbandonedUploads;
use App\Console\Commands\PruneMcpBindings;
use App\Console\Commands\PruneUsageEvents;
use App\Console\Commands\ReconcileSyncFanOut;
use App\Console\Commands\ReconcileUsageCounters;
use App\Console\Commands\RefreshCommunityStats;
use App\Console\Commands\RefreshExpiringTokens;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(DispatchDuePosts::class)->everyMinute()->withoutOverlapping();
Schedule::command(RefreshExpiringTokens::class)->cron('1,16,31,46 * * * *')->withoutOverlapping();
Schedule::command(PruneMcpBindings::class)->cron('11 * * * *');
Schedule::command(PruneAbandonedUploads::class)->cron('26 * * * *');

if (config('metrics.enabled')) {
    Schedule::command(CaptureMetrics::class)->cron('2,17,32,47 * * * *')->withoutOverlapping();
}

if (config('engagement.enabled')) {
    Schedule::command(DispatchDueReplyFetches::class)->cron('3,18,33,48 * * * *')->withoutOverlapping();
}

if (config('messages.enabled')) {
    Schedule::command(DispatchDueMessageFetches::class)->cron('4,19,34,49 * * * *')->withoutOverlapping();
}

if (config('repost.enabled')) {
    Schedule::command(DispatchDueReposts::class)->cron('6,21,36,51 * * * *')->withoutOverlapping();
}

if (config('sync.enabled')) {
    Schedule::command(ReconcileSyncFanOut::class)->cron('3,8,13,18,23,28,33,38,43,48,53,58 * * * *')->withoutOverlapping();
    Schedule::command(PollNativePosts::class)->cron('7,22,37,52 * * * *')->withoutOverlapping();
}

Schedule::command(ReconcileUsageCounters::class)->dailyAt('02:10')->withoutOverlapping();
Schedule::command(PruneUsageEvents::class)->dailyAt('02:20');

if (! config('subscriptions.enabled')) {
    Schedule::command(RefreshCommunityStats::class)->daily()->withoutOverlapping();
}
