<?php

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MediaRelocateCommand extends Command
{
    protected $signature = 'noerd:media-relocate
                            {--to=private : Target location: "private" (storage/app/media) or "public" (storage/app/public/media)}';

    protected $description = 'Move existing media files between the public and private storage roots when toggling media.private';

    public function handle(): int
    {
        $to = $this->option('to');

        if (! in_array($to, ['private', 'public'], true)) {
            $this->error('The --to option must be either "private" or "public".');

            return self::FAILURE;
        }

        $publicRoot = storage_path('app/public/media');
        $privateRoot = storage_path('app/media');

        [$source, $destination] = $to === 'private'
            ? [$publicRoot, $privateRoot]
            : [$privateRoot, $publicRoot];

        if (! File::isDirectory($source)) {
            $this->info("Nothing to move: source directory {$source} does not exist.");

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($destination);

        $moved = 0;
        $skipped = 0;

        foreach (File::allFiles($source) as $file) {
            $relativePath = $file->getRelativePathname();
            $target = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if (File::exists($target)) {
                $skipped++;

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            File::move($file->getPathname(), $target);
            $moved++;
        }

        $this->info("Moved {$moved} file(s) to {$destination}.");

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} file(s) already present at the destination.");
        }

        return self::SUCCESS;
    }
}
