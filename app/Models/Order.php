<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const PENDING_TTL_SECONDS = 7200;

    protected $table = 'v2_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'surplus_order_ids' => 'array'
    ];

    public function getExpiresAtAttribute(): int
    {
        return (int)$this->created_at + self::PENDING_TTL_SECONDS;
    }

    public function isExpiredAt(int $timestamp): bool
    {
        return $timestamp >= $this->expires_at;
    }
}
