<?php

namespace Noerd\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noerd\Helpers\TenantHelper;
use Noerd\Media\Models\Media;
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
     * extension and the storage path are derived from the file name.
     */
    public function file(int $tenantId, string $name, ?int $folderId = null): static
    {
        return $this->state(fn(): array => [
            'tenant_id' => $tenantId,
            'folder_id' => $folderId,
            'type' => 'image',
            'name' => $name,
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'path' => $tenantId . '/' . $name,
            'size' => 1,
        ]);
    }
}
