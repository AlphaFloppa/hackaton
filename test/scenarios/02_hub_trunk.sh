#!/usr/bin/env bash
# Сценарий 2: межгород через хаб и магистраль (HUB_TRUNK).
# Заказ товара СПб-продавца в ПВЗ-2: сборка -> STUB хаб -> STUB магистраль -> выдача.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000004"   # товар Продавца-2 (СПб)
SELLER="00000000-0000-0000-0000-5e1100000002"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-СПб-1' LIMIT 1" | tr -d '[:space:]')
K="s2-$TS"

echo "== Сценарий 2: хаб + магистраль =="
echo "1) Приёмка партии на ПВЗ-2 (4 ед.)"
curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-intake" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":4,\"storage_tariff_day\":6}"; echo

echo "2) Создание заказа (qty=1, preference=TIME)"
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-order" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":1,\"target_pvz_id\":\"$PVZ\",\"preference\":\"TIME\"}")
echo "$R"
OID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])')

echo "3) Сборка"
curl -sS -X POST "$API/orders/$OID/assemble" -H "Idempotency-Key: $K-asm"; echo

echo "4) STUB: обработка на хабе (ASSEMBLED -> IN_TRANSIT)"
curl -sS "$API/stub/hub/handle" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-hub" \
    -d "{\"order_id\":\"$OID\"}"; echo

echo "5) STUB: магистраль (IN_TRANSIT -> DELIVERED_TO_PVZ, 700 км, 2 кг)"
curl -sS "$API/stub/trunk/move" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-trunk" \
    -d "{\"order_id\":\"$OID\",\"chargeable_kg\":2,\"distance_km\":700}"; echo

echo "6) Выдача покупателю"
curl -sS -X POST "$API/orders/$OID/issue" -H "Idempotency-Key: $K-issue"; echo

echo "7) Итог по заказу"
curl -sS "$API/orders/$OID" | python3 -m json.tool | head -40
echo "== Сценарий 2 завершён: order_id=$OID =="
