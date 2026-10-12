<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConnectedAccountNativeWatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $connected_account_id
 * @property string $workspace_id
 * @property CarbonImmutable $enabled_at
 * @property string|null $last_seen_remote_id
 * @property CarbonImmutable|null $last_polled_at
 */
#[Fillable([
    'connected_account_id',
    'workspace_id',
    'enabled_at',
    'last_seen_remote_id',
    'last_polled_at',
    'enabled_by',
])]
#[WithoutIncrementing]
class ConnectedAccountNativeWatch extends Model
{
    /** @use HasFactory<ConnectedAccountNativeWatchFactory> */
    use HasFactory;

    #[Override]
    protected $primaryKey = 'connected_account_id';

    #[Override]
    protected $keyType = 'string';

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled_at' => 'immutable_datetime',
            'last_polled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
