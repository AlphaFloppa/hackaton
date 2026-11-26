<?php

declare(strict_types=1);

namespace App\Port\Routing;

/**
 * КОНТРАКТ на модуль определения маршрута (не наша область работы).
 * Модуль маршрутизации уже написан другой командой — мы только вызываем его
 * через эту интерфейсную границу и храним его результат в routing_decisions.
 */
interface RoutingModuleInterface
{
    /**
     * Решает схему доставки и возвращает полное решение с посегментной детализацией.
     *
     * @param string $orderId        UUID заказа
     * @param string $originNodeId   узел-источник (например "PVZ:<uuid>" или "SELLER:<uuid>")
     * @param string $targetPvzId    UUID целевого ПВЗ
     * @param array<int,array{seller_id:string,sku:string,qty:int,weight_kg:string,volume_m3:string}> $lines позиции заказа
     * @param string $preference    COST|TIME
     */
    public function decide(
        string $orderId,
        string $originNodeId,
        string $targetPvzId,
        array $lines,
        string $preference,
    ): RoutingDecision;
}
