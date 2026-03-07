<?php

namespace Noerd\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noerd\Media\Models\Media;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'type' => 'image',
            'name' => $this->faker->word() . '.pdf',
            'extension' => 'pdf',
            'path' => 'media/' . $this->faker->uuid() . '.pdf',
            'disk' => 'media',
            'size' => $this->faker->numberBetween(1000, 500000),
        ];
    }
}
