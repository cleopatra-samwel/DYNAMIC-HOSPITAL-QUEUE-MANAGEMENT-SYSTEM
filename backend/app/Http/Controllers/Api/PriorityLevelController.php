<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriorityLevel;

class PriorityLevelController extends Controller
{
    public function index()
    {
        return response()->json([
            'priority_levels' => PriorityLevel::orderByDesc('weight')->get(),
        ]);
    }
}
