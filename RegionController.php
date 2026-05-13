<?php

namespace App\Http\Controllers;

use App\Models\Region;

class RegionController extends Controller
{
    public function index()
    {
        $regions = \DB::connection('mongodb')
        ->table('region')
        ->get()
        ->map(function ($region) {
            return [
                'id' => (int) $region->id,
                'name' => $region->region_name,  // Changed from 'region_name' to 'name'
            ];
        });

        return response()->json($regions);
    }
}
