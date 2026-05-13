<?php

namespace App\Http\Controllers;

class ProvinceController extends Controller
{
    public function getByRegion($regionId)
    {
        $provinces = \DB::connection('mongodb')
            ->table('province')
            ->where('region_id', (int) $regionId)
            ->get()
            ->map(function ($province) {
                return [
                    'id' => (int) $province->id,
                    'name' => $province->province_name,  // Changed from 'province_name' to 'name'
                ];
            });

        return response()->json($provinces);
    }
}
