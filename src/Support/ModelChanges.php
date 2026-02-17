<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class ModelChanges
{
    private array $changes = [];

    public function __construct(private Model $model)
    {
        // 1. use the snapshot taken during `saving`
        if (method_exists($model, 'getCachedAttributeChanges')) {
            $this->changes = $model->getCachedAttributeChanges();
            // free memory immediately
            $model->clearCachedAttributeChanges();
        }

        // 2. last-ditch: compare current vs original (works when attributes
        //    were assigned then save() – but NOT after refresh()).
        if (empty($this->changes)) {
            foreach ($model->getDirty() as $key => $new) {
                $this->changes[$key] = [
                    'old' => $model->getOriginal($key),
                    'new' => $new,
                ];
            }
        }
    }

    public static function with(Model $model): self
    {
        return new self($model);
    }

    /* ------------------- public API ---------------------------------- */

    public function wasChanged(string $key): bool
    {
        return array_key_exists(key: $key, array: $this->changes);
    }

    public function old(string $key): mixed
    {
        return $this->changes[$key]['old'] ?? null;
    }

    public function new(string $key): mixed
    {
        return $this->changes[$key]['new'] ?? null;
    }

    public function all(): array
    {
        return $this->changes;
    }

    public function only(array $keys): array
    {
        return Arr::only($this->changes, $keys);
    }
}
