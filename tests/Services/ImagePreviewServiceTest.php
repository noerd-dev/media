<?php

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\ImagePreviewService;
use Noerd\Models\NoerdUser;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

function pdftoppmAvailable(): bool
{
    $process = new Process(['pdftoppm', '-v']);

    if (app()->environment('local')) {
        $process->setEnv(['PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin']);
    }

    try {
        $process->run();
    } catch (\Throwable $e) {
        return false;
    }

    return $process->getExitCode() === 0;
}

function writeRealPdf(string $absolutePath): void
{
    @mkdir(dirname($absolutePath), 0755, true);
    $pdf = Pdf::loadHTML('<h1>Sample</h1><p>Test PDF for ImagePreviewService.</p>');
    file_put_contents($absolutePath, $pdf->output());
}

it('generates a JPG thumbnail for a PDF via pdftoppm', function (): void {
    if (! pdftoppmAvailable()) {
        $this->markTestSkipped('pdftoppm is not installed — run `brew install poppler` (or apt-get install poppler-utils).');
    }

    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $relativePdfPath = $user->selected_tenant_id . '/sample.pdf';
    $absolutePdfPath = Storage::disk('media')->path($relativePdfPath);
    writeRealPdf($absolutePdfPath);

    $media = Media::factory()->create([
        'tenant_id' => $user->selected_tenant_id,
        'name' => 'sample.pdf',
        'extension' => 'pdf',
        'path' => $relativePdfPath,
        'size' => filesize($absolutePdfPath),
        'type' => 'file',
    ]);

    $service = app(ImagePreviewService::class);
    $thumbPath = $service->regenerateThumbnail($media);

    expect($thumbPath)
        ->not->toBeNull()
        ->toEndWith('.jpg')
        ->toStartWith($user->selected_tenant_id . '/thumbnails/pdf_');

    expect(Storage::disk('media')->exists($thumbPath))->toBeTrue();
    expect(filesize(Storage::disk('media')->path($thumbPath)))->toBeGreaterThan(0);
});

it('returns null and logs a warning when the PDF cannot be rasterized', function (): void {
    if (! pdftoppmAvailable()) {
        $this->markTestSkipped('pdftoppm is not installed — run `brew install poppler` (or apt-get install poppler-utils).');
    }

    Log::spy();

    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $relativePath = $user->selected_tenant_id . '/bogus.pdf';
    $absolutePath = Storage::disk('media')->path($relativePath);
    @mkdir(dirname($absolutePath), 0755, true);
    file_put_contents($absolutePath, 'this is not a real PDF');

    $media = Media::factory()->create([
        'tenant_id' => $user->selected_tenant_id,
        'name' => 'bogus.pdf',
        'extension' => 'pdf',
        'path' => $relativePath,
        'size' => filesize($absolutePath),
        'type' => 'file',
    ]);

    $service = app(ImagePreviewService::class);
    $thumbPath = $service->regenerateThumbnail($media);

    expect($thumbPath)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'PDF thumbnail generation failed'))
        ->once();
});

it('still produces thumbnails for JPG through the Intervention branch', function (): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $relativePath = $user->selected_tenant_id . '/sample.jpg';
    $absolutePath = Storage::disk('media')->path($relativePath);
    @mkdir(dirname($absolutePath), 0755, true);

    $image = imagecreatetruecolor(400, 300);
    imagejpeg($image, $absolutePath);
    imagedestroy($image);

    $media = Media::factory()->create([
        'tenant_id' => $user->selected_tenant_id,
        'name' => 'sample.jpg',
        'extension' => 'jpg',
        'path' => $relativePath,
        'size' => filesize($absolutePath),
        'type' => 'image',
    ]);

    $service = app(ImagePreviewService::class);
    $thumbPath = $service->regenerateThumbnail($media);

    expect($thumbPath)
        ->not->toBeNull()
        ->toEndWith('.jpg')
        ->toStartWith($user->selected_tenant_id . '/thumbnails/thumb_');

    expect(Storage::disk('media')->exists($thumbPath))->toBeTrue();
});
