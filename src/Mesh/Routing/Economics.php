<?php

declare(strict_types=1);

namespace Mesh\Routing;

final class Economics
{
    public function __construct(private readonly array $model)
    {
    }

    public function compare(array $request, array $snapshot, array $comparators, array $eligiblePaths): array
    {
        $mesh = $comparators['mesh'] === null ? null : $this->calculate(
            'MESH', $request, $snapshot, $comparators['mesh'], $eligiblePaths['mesh'] !== null,
        );
        $hub = $comparators['hub'] === null ? null : $this->calculate(
            'HUB_TRUNK', $request, $snapshot, $comparators['hub'], $eligiblePaths['hub'] !== null,
        );

        $comparison = null;
        $conditions = null;
        if ($mesh !== null && $hub !== null) {
            $comparison = [
                'transfer_saving' => self::delta($hub['transfer_cents'], $mesh['transfer_cents']),
                'marketplace_saving' => self::delta($hub['marketplace']['cost_cents'], $mesh['marketplace']['cost_cents']),
                'pvz_result_gain' => self::delta($mesh['pvz']['result_cents'], $hub['pvz']['result_cents'], $hub['pvz']['result_cents']),
                'seller_profit_gain' => $mesh['seller']['profit_cents'] === null || $hub['seller']['profit_cents'] === null
                    ? null : self::delta($mesh['seller']['profit_cents'], $hub['seller']['profit_cents'], $hub['seller']['profit_cents']),
                'buyer_delivery_saving' => self::delta($hub['buyer']['delivery_cents'], $mesh['buyer']['delivery_cents']),
                'time_saved' => [
                    'hours' => Router::hours($hub['minutes'] - $mesh['minutes']),
                    'pct' => self::percent($hub['minutes'] - $mesh['minutes'], $hub['minutes']),
                ],
            ];
            $terms = $this->model['assumptions'];
            $conditions = [
                'mesh_eligible' => $mesh['eligible'],
                'pvz_profitable' => $mesh['pvz']['result_cents'] > 0,
                'marketplace_break_even' => $mesh['marketplace']['margin_cents'] === null
                    ? null : $mesh['marketplace']['margin_cents'] >= 0,
                'marketplace_no_cost_increase' => $mesh['marketplace']['cost_cents'] <= $hub['marketplace']['cost_cents'],
                'buyer_price_within_limit' => $mesh['buyer']['delivery_cents'] - $hub['buyer']['delivery_cents'] <= $terms['buyer_max_extra_cents'],
                'buyer_time_within_limit' => $mesh['minutes'] - $hub['minutes'] <= $terms['buyer_max_extra_minutes'],
            ];
        }

        return [
            'type' => 'FORECAST',
            'model_version' => $this->model['version'],
            'storage_days' => $request['storage_days'],
            'mesh' => $mesh,
            'base' => $hub,
            'comparison' => $comparison,
            'pilot_conditions' => $conditions,
        ];
    }

    private function calculate(string $scheme, array $request, array $snapshot, array $path, bool $eligible): array
    {
        $t = $this->model['tariffs'];
        $a = $this->model['assumptions'];
        $qty = $request['qty'];
        $days = $request['storage_days'];
        $source = $path['source'];
        $target = $request['target_pvz_id'];
        $price = $this->itemTotal($snapshot['item'], 'unit_price_cents', $qty);
        $cogs = $this->itemTotal($snapshot['item'], 'unit_cogs_cents', $qty);
        $pvzFees = [];
        if ($source['kind'] === 'PVZ') {
            $id = $source['location_id'];
            $fees = array_replace($t['pvz'], $snapshot['pvzs'][$id]['fees'] ?? []);
            $pvzFees[$id] = [
                'intake_cents' => $fees['intake_unit_cents'] * $qty,
                'assembly_cents' => $fees['assembly_order_cents'],
                'storage_cents' => $scheme === 'MESH' ? $fees['storage_unit_day_cents'] * $qty * $days : 0,
            ];
        }
        $pvzFees[$target]['issue_cents'] = ($snapshot['pvzs'][$target]['fees']['issue_order_cents'] ?? $t['pvz']['issue_order_cents']);

        $pvzIncome = 0;
        $pvzCost = 0;
        foreach ($pvzFees as &$fees) {
            $income = array_sum($fees);
            $cost = self::basisPoints($income, $a['pvz_operation_cost_bps']);
            $fees += ['income_cents' => $income, 'cost_cents' => $cost, 'result_cents' => $income - $cost];
            $pvzIncome += $income;
            $pvzCost += $cost;
        }
        unset($fees);

        $legs = ['MESH' => 0, 'FIRST_MILE' => 0, 'LAST_MILE' => 0, 'HANDLING' => 0, 'TRUNK' => 0];
        foreach ($path['path'] as $edge) {
            $legs[$edge['kind']] += $edge['cost'];
        }
        $risk = $a['risk'][$scheme];
        $expectedReturns = self::basisPoints($risk['return_cents'], $risk['return_bps']);
        $expectedIncidents = self::basisPoints($risk['incident_cents'], $risk['incident_bps']);
        $hubStorage = $scheme === 'HUB_TRUNK' ? $t['hub_storage_unit_day_cents'] * $qty * $days : 0;
        $marketCost = $path['cost'] + $pvzIncome + $hubStorage + $expectedReturns + $expectedIncidents;
        $sellerShipping = $t['seller_shipping_cents'];
        $sellerStorage = $t['seller_storage_unit_day_cents'] * $qty * $days;
        $buyerDelivery = $t['buyer_delivery_cents'];
        $commission = $price === null ? null : self::basisPoints($price, $t['commission_bps']);
        $marketRevenue = $commission === null ? null : $commission + $sellerShipping + $sellerStorage + $buyerDelivery;

        return [
            'scheme' => $scheme,
            'eligible' => $eligible,
            'source_name' => $source['name'],
            'minutes' => $path['minutes'],
            'transfer_cents' => $path['cost'],
            'transfer_by_leg_cents' => $legs,
            'prior_transfer_cents' => $path['spent_cents'],
            'hub_storage_cents' => $hubStorage,
            'expected_return_cents' => $expectedReturns,
            'expected_incident_cents' => $expectedIncidents,
            'pvz' => [
                'income_cents' => $pvzIncome, 'cost_cents' => $pvzCost, 'result_cents' => $pvzIncome - $pvzCost,
                'by_location' => $pvzFees,
            ],
            'marketplace' => [
                'revenue_cents' => $marketRevenue, 'cost_cents' => $marketCost,
                'margin_cents' => $marketRevenue === null ? null : $marketRevenue - $marketCost,
            ],
            'seller' => [
                'sale_cents' => $price, 'cogs_cents' => $cogs, 'commission_cents' => $commission,
                'shipping_cents' => $sellerShipping, 'storage_cents' => $sellerStorage,
                'profit_cents' => $price === null || $cogs === null ? null : $price - $cogs - $commission - $sellerShipping - $sellerStorage,
            ],
            'buyer' => [
                'delivery_cents' => $buyerDelivery, 'total_cents' => $price === null ? null : $price + $buyerDelivery,
                'hours' => Router::hours($path['minutes']),
            ],
        ];
    }

    private function itemTotal(array $item, string $key, int $qty): ?int
    {
        $value = $item[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))
            || $value < 0 || $value > 1_000_000_000) {
            throw new \InvalidArgumentException("Проверьте поле товара $key в копейках.");
        }
        return (int) $value * $qty;
    }

    private static function basisPoints(int $amount, int $bps): int
    {
        return (int) round($amount * ($bps / 10_000));
    }

    private static function delta(int $first, int $second, ?int $base = null): array
    {
        return ['rub' => Router::rubles($first - $second), 'pct' => self::percent($first - $second, $base ?? $first)];
    }

    private static function percent(int $difference, int $base): ?float
    {
        return $base === 0 ? null : round(100 * $difference / abs($base), 2);
    }
}
