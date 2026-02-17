<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kraite\Core\Abstracts\BaseModel;

final class ApiSnapshot extends BaseModel
{
    protected $casts = [
        'api_response' => 'array',
    ];

    /**
     * Store or update the API snapshot for a given model and canonical key.
     *
     * @param  Position|Order|Account  $model
     */
    public static function storeFor(Model $model, string $canonical, array $payload): self
    {
        /** @var self $snapshot */
        return $model->apiSnapshots()->updateOrCreate(
            ['canonical' => $canonical],
            ['api_response' => $payload]
        );
    }

    /**
     * Retrieve the API snapshot response for a given model and canonical key.
     *
     * @param  Position|Order|Account  $model
     */
    public static function getFrom(Model $model, string $canonical): ?array
    {
        /** @var self|null $snapshot */
        $snapshot = $model->apiSnapshots()
            ->where('canonical', $canonical)
            ->latest('id')
            ->first();

        return $snapshot?->api_response;
    }

    public function responsable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeWithCanonical($query, string $canonical)
    {
        return $query->where('canonical', $canonical);
    }
}
