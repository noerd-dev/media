<?php

use Illuminate\Support\Facades\Log;
use Noerd\Media\Services\PdfThumbnailGenerator;
use Noerd\Media\Tests\Support\FakeGhostscript;
use Noerd\Media\Tests\Support\PdfRendering;

uses(Tests\TestCase::class);

afterEach(function (): void {
    FakeGhostscript::cleanup();
});

function generatorDestination(): string
{
    $path = sys_get_temp_dir() . '/pdf-thumb-' . uniqid() . '.jpg';

    register_shutdown_function(fn() => @unlink($path));

    return $path;
}

it('renders the thumbnail through the configured Ghostscript binary', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingJpeg()]);

    $destination = generatorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeTrue();
    expect(file_exists($destination))->toBeTrue();
    expect(filesize($destination))->toBeGreaterThan(0);
});

it('fails and logs a warning when Ghostscript cannot rasterize the file', function (): void {
    Log::spy();

    config(['media.ghostscript_binary' => FakeGhostscript::failing()]);

    $destination = generatorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeFalse();
    expect(file_exists($destination))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn(string $message) => str_contains($message, 'PDF thumbnail generation failed'))
        ->once();
});

it('treats an empty output file as a failure and cleans it up', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingEmptyFile()]);

    $destination = generatorDestination();

    expect(app(PdfThumbnailGenerator::class)->generate('/some/source.pdf', $destination))->toBeFalse();
    expect(file_exists($destination))->toBeFalse();
});

it('reports that PDF rasterization is available once a binary is configured', function (): void {
    config(['media.ghostscript_binary' => FakeGhostscript::writingJpeg()]);

    expect(app(PdfThumbnailGenerator::class)->isAvailable())->toBeTrue();
});

it('rasterizes a real PDF when a renderer is installed', function (): void {
    if (! PdfRendering::isWorking()) {
        $this->markTestSkipped('This host cannot rasterize PDFs — install Ghostscript (brew install ghostscript).');
    }

    $generator = app(PdfThumbnailGenerator::class);

    $source = sys_get_temp_dir() . '/pdf-thumb-source-' . uniqid() . '.pdf';
    file_put_contents($source, Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Sample</h1>')->output());

    $destination = generatorDestination();

    expect($generator->generate($source, $destination))->toBeTrue();
    expect(filesize($destination))->toBeGreaterThan(0);

    @unlink($source);
});
