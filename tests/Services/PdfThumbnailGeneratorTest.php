<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Noerd\Media\Services\PdfThumbnailGenerator;
use Noerd\Media\Tests\Support\FakeGhostscript;

uses(Tests\TestCase::class);

afterEach(function (): void {
    FakeGhostscript::cleanup();
});

function zzGeneratorDestination(): string
{
    $path = sys_get_temp_dir() . '/pdf-thumb-' . uniqid() . '.jpg';

    register_shutdown_function(fn() => @unlink($path));

    return $path;
}

it('renders the thumbnail through the configured Ghostscript binary', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingJpeg()]);

    $destination = zzGeneratorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeTrue();
    expect(file_exists($destination))->toBeTrue();
    expect(filesize($destination))->toBeGreaterThan(0);
});

it('fails and logs a warning when Ghostscript cannot rasterize the file', function (): void {
    Log::spy();

    config(['media.ghostscript_binary' => FakeGhostscript::failing()]);

    $destination = zzGeneratorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeFalse();
    expect(file_exists($destination))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn(string $message) => str_contains($message, 'PDF thumbnail generation failed'))
        ->once();
});

it('treats an empty output file as a failure and cleans it up', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingEmptyFile()]);

    $destination = zzGeneratorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeFalse();
    expect(file_exists($destination))->toBeFalse();
});

it('reports that PDF rasterization is available once a binary is configured', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingJpeg()]);

    expect(app(PdfThumbnailGenerator::class)->isAvailable())->toBeTrue();
});
