<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\ImagePreviewService;
use Noerd\Media\Tests\Support\FakeGhostscript;
use Noerd\Media\Tests\Support\PdfRendering;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

afterEach(function (): void {
    FakeGhostscript::cleanup();
});

function zzPdfRenderingAvailable(): bool
{
    return PdfRendering::isWorking();
}

function zzWriteRealPdf(string $absolutePath): void
{
    @mkdir(dirname($absolutePath), 0755, true);
    $pdf = Pdf::loadHTML('<h1>Sample</h1><p>Test PDF for ImagePreviewService.</p>');
    file_put_contents($absolutePath, $pdf->output());
}

it('generates a JPG thumbnail for a PDF', function (): void {
    if (! zzPdfRenderingAvailable()) {
        $this->markTestSkipped('This host cannot rasterize PDFs — install Ghostscript (brew install ghostscript).');
    }

    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $relativePdfPath = $user->selected_tenant_id . '/sample.pdf';
    $absolutePdfPath = Storage::disk('media')->path($relativePdfPath);
    zzWriteRealPdf($absolutePdfPath);

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

it('returns null and leaves the thumbnail unset when the PDF cannot be rasterized', function (): void {
    // A stubbed binary keeps this deterministic on hosts without Ghostscript.
    config(['media.ghostscript_binary' => FakeGhostscript::failing()]);

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
    expect($media->refresh()->thumbnail)->toBeNull();
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
