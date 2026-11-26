<?php

declare(strict_types=1);

return [
    'version' => 'hackathon-v1',
    'source_note' => 'Демонстрационные допущения команды, ₽ и часы в API',
    'tariffs' => [
        // Деньги в копейках. Ставки за км и кг могут быть дробными.
        'mesh' => ['dispatch_cents' => 2000, 'per_kg_km_cents' => 55, 'wait_min' => 180, 'kmph' => 40],
        'first_mile' => ['dispatch_cents' => 4800, 'per_kg_km_cents' => 0, 'wait_min' => 240, 'kmph' => 35],
        'last_mile' => ['dispatch_cents' => 2500, 'per_kg_km_cents' => 0, 'wait_min' => 240, 'kmph' => 35],
        'hub' => ['handling_in_cents' => 1500, 'sorting_cents' => 1000, 'handling_out_cents' => 1500, 'minutes' => 360],
        'trunk' => ['per_kg_km_cents' => 12, 'wait_min' => 120, 'kmph' => 60],
        'pvz' => ['intake_unit_cents' => 400, 'assembly_order_cents' => 1800, 'issue_order_cents' => 1200, 'storage_unit_day_cents' => 150],
        'hub_storage_unit_day_cents' => 70,
        'seller_shipping_cents' => 3500,
        'seller_storage_unit_day_cents' => 80,
        'buyer_delivery_cents' => 0,
        'commission_bps' => 1800,
    ],
    'assumptions' => [
        'volumetric_kg_per_m3' => 250,
        'mesh_max_km' => 20,
        'default_city_sla_hours' => 24,
        'default_intercity_sla_hours' => 72,
        'default_storage_days' => 1,
        'pvz_operation_cost_bps' => 4000,
        'demo_incident' => ['fixed_cents' => 3000, 'share_bps' => 2000, 'delay_minutes' => 360],
        'risk' => [
            'MESH' => ['return_bps' => 500, 'return_cents' => 3000, 'incident_bps' => 200, 'incident_cents' => 4500],
            'HUB_TRUNK' => ['return_bps' => 500, 'return_cents' => 5000, 'incident_bps' => 100, 'incident_cents' => 6000],
        ],
        'buyer_max_extra_cents' => 0,
        'buyer_max_extra_minutes' => 120,
        // Доход и себестоимость товара передаются в снимке заказа.
    ],
];
