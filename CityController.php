<?php

namespace App\Http\Controllers;

class CityController extends Controller
{
    public function getByProvince($provinceId)
    {
        $cities = \DB::connection('mongodb')
            ->table('city')
            ->where('province_id', (int) $provinceId)
            ->get()
            ->map(function ($city) {
                return [
                    'id' => (int) $city->id,
                    'name' => $city->city_name,  // Changed from 'city_name' to 'name'
                ];
            });

        return response()->json($cities);
    }
}
