<?php

declare(strict_types=1);

const MUG = '10000000-0000-4000-8000-000000000001';
const JACKET = '10000000-0000-4000-8000-000000000002';
const MSK1 = '20000000-0000-4000-8000-000000000001';
const MSK2 = '20000000-0000-4000-8000-000000000002';
const MSK3 = '20000000-0000-4000-8000-000000000003';
const SPB1 = '20000000-0000-4000-8000-000000000004';
const SELLER = '30000000-0000-4000-8000-000000000001';
const HUB_MSK = '40000000-0000-4000-8000-000000000001';
const HUB_SPB = '40000000-0000-4000-8000-000000000002';

function fixture(): array
{
    return [
        'items' => [
            MUG => ['id' => MUG, 'name' => 'Кружка', 'kg' => 0.4, 'volume_m3' => 0.002,
                'unit_price_cents' => 45000, 'unit_cogs_cents' => 20000],
            JACKET => ['id' => JACKET, 'name' => 'Куртка', 'kg' => 1.5, 'volume_m3' => 0.03,
                'unit_price_cents' => 420000, 'unit_cogs_cents' => 240000],
        ],
        'pvzs' => [
            MSK1 => ['name' => 'ПВЗ Москва-1', 'city_id' => 'MSK', 'address' => 'Тверская, 7', 'lat' => 55.76, 'lng' => 37.61,
                'capacity_units' => 100, 'used_units' => 30, 'is_active' => true],
            MSK2 => ['name' => 'ПВЗ Москва-2', 'city_id' => 'MSK', 'address' => 'Арбат, 20', 'lat' => 55.75, 'lng' => 37.59,
                'capacity_units' => 60, 'used_units' => 15, 'is_active' => true],
            MSK3 => ['name' => 'ПВЗ Москва-3', 'city_id' => 'MSK', 'address' => 'Ленинский пр., 90', 'lat' => 55.69, 'lng' => 37.53,
                'capacity_units' => 30, 'used_units' => 26, 'is_active' => true],
            SPB1 => ['name' => 'ПВЗ СПб-1', 'city_id' => 'SPB', 'address' => 'Невский пр., 50', 'lat' => 59.93, 'lng' => 30.34,
                'capacity_units' => 80, 'used_units' => 20, 'is_active' => true],
        ],
        'hubs' => [
            HUB_MSK => ['name' => 'Хаб Москва', 'city_id' => 'MSK', 'lat' => 55.74, 'lng' => 37.57],
            HUB_SPB => ['name' => 'Хаб Санкт-Петербург', 'city_id' => 'SPB', 'lat' => 59.90, 'lng' => 30.40],
        ],
        'sellers' => [
            SELLER => ['id' => SELLER, 'name' => 'ИП Орлов', 'city_id' => 'MSK', 'lat' => 55.71, 'lng' => 37.55],
        ],
        'stock' => [
            ['batch_id' => '50000000-0000-4000-8000-000000000001', 'item_id' => MUG, 'kind' => 'PVZ', 'location_id' => MSK2,
                'available_qty' => 15, 'received_at' => '2026-11-24T10:00:00Z'],
            ['batch_id' => '50000000-0000-4000-8000-000000000002', 'item_id' => JACKET, 'kind' => 'PVZ', 'location_id' => MSK1,
                'available_qty' => 30, 'received_at' => '2026-11-24T10:00:00Z'],
        ],
    ];
}

function snapshotFor(array $fixture, string $item): array
{
    if (!isset($fixture['items'][$item])) {
        throw new InvalidArgumentException('Тестовый товар не найден.');
    }
    $fixture['item'] = $fixture['items'][$item];
    $fixture['stock'] = array_values(array_filter($fixture['stock'], static fn (array $row): bool => $row['item_id'] === $item));
    return $fixture;
}
