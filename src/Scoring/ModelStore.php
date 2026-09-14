<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Illuminate\Support\Facades\Storage;

/**
 * Where the fitted model lives: one JSON file on the local disk.
 *
 * A file rather than the cache, because a cache flush must not silently
 * turn a calibrated site back into an uncalibrated one; and rather than an
 * entry, because it is not content. The disk is the host's `local` disk,
 * which every Laravel application has.
 */
final class ModelStore
{
    public const PATH = 'statamic-scholarly/model.json';

    public function __construct(private readonly string $disk = 'local') {}

    public function load(): ?LogisticModel
    {
        $storage = Storage::disk($this->disk);

        if (! $storage->exists(self::PATH)) {
            return null;
        }

        $decoded = \json_decode((string) $storage->get(self::PATH), true);

        if (! \is_array($decoded) || ! \is_array($decoded['weights'] ?? null)) {
            return null;
        }

        $weights = [];

        foreach (Features::TERMS as $term) {
            if (! \is_numeric($decoded['weights'][$term] ?? null)) {
                // A model file from a version with different terms is not
                // a model; the prior is honest and this is not.
                return null;
            }

            $weights[$term] = (float) $decoded['weights'][$term];
        }

        return new LogisticModel($weights, 'calibrated', \is_array($decoded['meta'] ?? null) ? $decoded['meta'] : []);
    }

    public function save(LogisticModel $model): void
    {
        Storage::disk($this->disk)->put(self::PATH, (string) \json_encode([
            'weights' => $model->weights(),
            'meta' => $model->meta,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function forget(): void
    {
        Storage::disk($this->disk)->delete(self::PATH);
    }
}
