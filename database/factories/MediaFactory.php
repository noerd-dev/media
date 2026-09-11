<?php

namespace Noerd\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noerd\Helpers\TenantHelper;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaPathService;
use Noerd\Models\Tenant;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            // The selected tenant when a user acts (matches the BelongsToTenant
            // stamping), otherwise a fresh tenant so the record is always valid.
            'tenant_id' => fn(): int => TenantHelper::currentTenantId() ?? Tenant::factory()->create()->id,
            'type' => 'image',
            'name' => $this->faker->word() . '.pdf',
            'extension' => 'pdf',
            'path' => 'media/' . $this->faker->uuid() . '.pdf',
            'disk' => 'media',
            'size' => $this->faker->numberBetween(1000, 500000),
        ];
    }

    /**
     * A stored file of one tenant, named as it appears in the media list: the
     * extension and the storage path are derived from the file name and the
     * folder — the disk mirrors the library, so a fixture in a folder must sit
     * in that folder's directory.
     */
    public function file(int $tenantId, string $name, ?int $folderId = null): static
    {
        return $this->state(function () use ($tenantId, $name, $folderId): array {
            $folder = $folderId === null
                ? null
                : MediaFolder::withoutGlobalScopes()->find($folderId);

            return [
                'tenant_id' => $tenantId,
                'folder_id' => $folderId,
                'type' => 'image',
                'name' => $name,
                'extension' => pathinfo($name, PATHINFO_EXTENSION),
                'path' => app(MediaPathService::class)->pathFor($tenantId, $folder, $name),
                'size' => 1,
            ];
        });
    }
}
