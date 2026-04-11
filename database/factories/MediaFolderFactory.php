<?php

namespace Noerd\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noerd\Media\Models\MediaFolder;

class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'parent_id' => null,
            'name' => $this->faker->unique()->words(2, true),
        ];
    }
}
