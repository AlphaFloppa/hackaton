#!/usr/bin/env bash
# Сценарий 1: городской заказ (MESH).
# Городской заказ через городскую mesh-сеть: приёмка -> заказ -> сборка -> выдача.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="cccccccc-1111-1111-1111-111111111111"   # товар Продавца-1 (Москва)
SELLER="aaaaaaaa-1111-1111-1111-111111111111"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-1' LIMIT 1" | tr -d '[:space:]')
K="s1-$TS"   # префикс Idempotency-Key (перезапуск сценария безопасен)

echo "== Сценарий 1: городской заказ =="
echo "1) Приёмка партии на ПВЗ-1 (5 ед.)"
curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-intake" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":5,\"storage_tariff_day\":5}"; echo

echo "2) Создание заказа (qty=2, preference=COST)"
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-order" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":2,\"target_pvz_id\":\"$PVZ\",\"preference\":\"COST\"}")
echo "$R"
OID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])')

echo "3) Сборка"
curl -sS -X POST "$API/orders/$OID/assemble" -H "Idempotency-Key: $K-asm"; echo

echo "4) Выдача покупателю"
curl -sS -X POST "$API/orders/$OID/issue" -H "Idempotency-Key: $K-issue"; echo

echo "5) Повторная выдача с тем же ключом (ожидаем already_processed)"
curl -sS -X POST "$API/orders/$OID/issue" -H "Idempotency-Key: $K-issue"; echo

echo "6) Итог по заказу (маршрут, сегменты, экономика)"
curl -sS "$API/orders/$OID" | python3 -m json.tool | head -40
echo "== Сценарий 1 завершён: order_id=$OID =="
