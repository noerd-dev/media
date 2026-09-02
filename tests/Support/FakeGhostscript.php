<?php

declare(strict_types=1);

namespace Noerd\Media\Tests\Support;

/**
 * Creates throwaway shell stubs that stand in for the Ghostscript binary, so
 * the PDF thumbnail pipeline can be tested without Ghostscript — and without
 * the optional imagick extension — being installed.
 */
class FakeGhostscript
{
    /**
     * @var list<string>
     */
    private static array $created = [];

    /**
     * A stub that writes a non-empty file to -sOutputFile and exits 0.
     */
    public static function writingJpeg(): string
    {
        return self::make('printf \'\\377\\330\\377\\340JPEGSTUB\' > "$out"');
    }

    /**
     * A stub that writes nothing and exits non-zero, like Ghostscript does for
     * an input that is not a PDF.
     */
    public static function failing(): string
    {
        return self::make('echo "**** Unable to open the initial device." >&2' . "\n" . 'exit 1');
    }

    /**
     * A stub that exits 0 but leaves an empty output file behind.
     */
    public static function writingEmptyFile(): string
    {
        return self::make(': > "$out"');
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            @unlink($path);
        }

        self::$created = [];
    }

    /**
     * Write an executable /bin/sh stub that exposes the -sOutputFile value as
     * $out and then runs $body.
     */
    private static function make(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake-gs-');

        $script = <<<SH
        #!/bin/sh
        out=""
        for arg in "\$@"; do
            case "\$arg" in
                -sOutputFile=*) out="\${arg#-sOutputFile=}" ;;
            esac
        done
        {$body}
        SH;

        file_put_contents($path, $script . "\n");
        chmod($path, 0755);

        self::$created[] = $path;

        return $path;
    }
}
