<?php

namespace Database\Seeders;

use App\Models\PriorityLevel;
use Illuminate\Database\Seeder;

class PriorityLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['name' => 'Critical', 'weight' => 100],
            ['name' => 'High', 'weight' => 60],
            ['name' => 'Medium', 'weight' => 30],
            ['name' => 'Normal', 'weight' => 10],
        ];

        foreach ($levels as $level) {
            PriorityLevel::updateOrCreate(['name' => $level['name']], $level);
        }
    }
}
