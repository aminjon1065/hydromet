<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Turns a non-numeric record segment into a 404 before it reaches the database.
 *
 * Filament registers a view page as `/{record}`, so any unmatched path under a
 * resource — `/admin/stations/create`, for example — arrives here as a record
 * key. These models are keyed by bigint: PostgreSQL raises a driver error when
 * such a key is compared to a word, which would answer a mistyped URL with 500
 * instead of "not found". SQLite compares it happily and returns nothing, so
 * the difference only shows on the production driver.
 */
trait ResolvesNumericRecordKey
{
    protected function resolveRecord(int|string $key): Model
    {
        if (! is_int($key) && ! ctype_digit($key)) {
            throw (new ModelNotFoundException)->setModel($this->getModel(), [$key]);
        }

        return parent::resolveRecord($key);
    }
}
