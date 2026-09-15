<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasUuid
{
    public function initializeHasUuid(): void
    {
        $this->setIncrementing(false);
        $this->setKeyType('string');
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}