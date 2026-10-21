<?php

declare(strict_types=1);

namespace Noerd\Media\Tests\Support;

/**
 * Throwaway shell stubs standing in for Poppler's pdftoppm, so the second PDF
 * renderer can be tested without Poppler being installed. Like the real
 * binary, a stub receives an output PREFIX as its last argument and writes
 * `{prefix}.jpg`.
 */
class FakePdftoppm
{
    /**
     * @var list<string>
     */
    private static array $created = [];

    /**
     * A stub that writes a non-empty `{prefix}.jpg` and exits 0.
     */
    public static function writingJpeg(): string
    {
        return self::make('printf \'\\377\\330\\377\\340JPEGSTUB\' > "$prefix.jpg"');
    }

    /**
     * A stub that writes nothing and exits non-zero, like pdftoppm does for an
     * input that is not a PDF.
     */
    public static function failing(): string
    {
        return self::make('echo "Syntax Error: Couldn\'t find trailer dictionary" >&2' . "\n" . 'exit 1');
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            @unlink($path);
        }

        self::$created = [];
    }

    /**
     * Write an executable /bin/sh stub that exposes the last argument as
     * $prefix and then runs $body.
     */
    private static function make(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake-pdftoppm-');

        $script = <<<SH
        #!/bin/sh
        for arg in "\$@"; do prefix="\$arg"; done
        {$body}
        SH;

        file_put_contents($path, $script . "\n");
        chmod($path, 0755);

        self::$created[] = $path;

        return $path;
    }
}
