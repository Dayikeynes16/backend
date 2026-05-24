<?php

namespace Database\Factories;

use App\Models\AgendaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgendaItemFactory extends Factory
{
    protected $model = AgendaItem::class;

    public function definition(): array
    {
        return [
            'type' => 'task',
            'title' => $this->faker->sentence(3),
            'scope' => 'personal',
            'recurrence' => 'none',
            'all_day' => false,
        ];
    }
}
