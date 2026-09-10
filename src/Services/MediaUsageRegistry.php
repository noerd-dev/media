<?php

declare(strict_types=1);

namespace Noerd\Media\Services;

use Noerd\Media\Models\Media;
use Throwable;

/**
 * Who still needs a file. Modules register a check from their service provider's
 * boot() and return a human-readable reason when the media must not be deleted —
 * "Used by 2 receipts", say.
 *
 * The media library must not know which modules exist (ModuleBoundaryTest
 * enforces that), so the question is asked outward rather than answered here. A
 * registration ceases to exist with its module — same reasoning as the noerd
 * core's PicklistRegistry and DetailSlotsRegistry.
 */
class MediaUsageRegistry
{
    /** @var array<string, callable(Media): ?string> */
    private array $checks = [];

    /**
     * @param  callable(Media): ?string  $check  returns the reason, or null when this module does not need the file
     */
    public function register(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    /**
     * Every reason the file is still needed. Empty means it may be deleted.
     *
     * @return array<int, string>
     */
    public function reasons(Media $media): array
    {
        $reasons = [];

        foreach ($this->checks as $name => $check) {
            try {
                $reason = $check($media);
            } catch (Throwable $e) {
                // A module that cannot answer must not make the file
                // undeletable — but the silence would be worse than the log.
                report($e);

                continue;
            }

            if (is_string($reason) && mb_trim($reason) !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * The first reason the file is still needed, or null when it is free.
     */
    public function firstReason(Media $media): ?string
    {
        return $this->reasons($media)[0] ?? null;
    }
}
