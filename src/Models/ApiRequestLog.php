<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Database\Factories\ApiRequestLogFactory;

final class ApiRequestLog extends BaseModel
{
    use HasFactory;

    protected $table = 'api_request_logs';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ApiRequestLogFactory
    {
        return ApiRequestLogFactory::new();
    }

    protected $casts = [
        'debug_data' => 'array',
        'payload' => 'array',
        'http_headers_sent' => 'array',
        'response' => 'array',
        'http_headers_returned' => 'array',

        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function relatable()
    {
        return $this->morphTo();
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
