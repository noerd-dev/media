<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Noerd\Media\Services\PdfThumbnailGenerator;
use Noerd\Media\Tests\Support\FakeGhostscript;
use Noerd\Media\Tests\Support\FakePdftoppm;

uses(Tests\TestCase::class);

afterEach(function (): void {
    FakeGhostscript::cleanup();
    FakePdftoppm::cleanup();
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

/**
 * A configured but non-executable path disables Ghostscript — it is then not
 * auto-detected either, whatever the test machine has installed.
 */
function zzWithoutGhostscript(): void
{
    config(['media.ghostscript_binary' => sys_get_temp_dir() . '/zz-no-ghostscript']);
}

it('renders the thumbnail through pdftoppm when no Ghostscript is available', function (): void {
    zzWithoutGhostscript();
    config(['media.pdftoppm_binary' => FakePdftoppm::writingJpeg()]);

    $destination = zzGeneratorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeTrue();
    expect(filesize($destination))->toBeGreaterThan(0);
    expect(app(PdfThumbnailGenerator::class)->isAvailable())->toBeTrue();
});

it('fails and logs a warning when pdftoppm cannot rasterize the file', function (): void {
    Log::spy();

    zzWithoutGhostscript();
    config(['media.pdftoppm_binary' => FakePdftoppm::failing()]);

    $destination = zzGeneratorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeFalse();
    expect(file_exists($destination))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn(string $message) => str_contains($message, 'PDF thumbnail generation failed'))
        ->once();
});

it('prefers Ghostscript over pdftoppm when both are available', function (): void {
    config([
        'media.ghostscript_binary' => FakeGhostscript::failing(),
        'media.pdftoppm_binary' => FakePdftoppm::writingJpeg(),
    ]);

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', zzGeneratorDestination()))->toBeFalse();
});
