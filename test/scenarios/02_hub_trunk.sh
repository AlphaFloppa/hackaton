#!/usr/bin/env bash
# Сценарий 2: межгород через хаб и магистраль (HUB_TRUNK).
# Заказ товара СПб-продавца в московский ПВЗ: сборка -> STUB хаб -> STUB магистраль -> выдача.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000005"   # товар Продавца-2 (СПб)
SELLER="00000000-0000-0000-0000-5e1100000002"
SOURCE="00000000-0000-0000-0000-f00d00000003"
PVZ="00000000-0000-0000-0000-f00d00000002"
K="s2-$TS"

receive() {
    local point="$1" key="$2" supply sid
    supply=$(curl -fsS "$API/supplies" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-$key-supply" \
        -d "{\"seller_id\":\"$SELLER\",\"item_id\":\"$ITEM\",\"pvz_id\":\"$point\",\"qty\":1}") || return
    sid=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])' <<< "$supply") || return
    curl -fsS -X POST "$API/supplies/$sid/accept" -H "Idempotency-Key: $K-$key-intake"
}

echo "== Сценарий 2: хаб + магистраль =="
echo "1) Завоз и приёмка товара в Санкт-Петербурге"
receive "$SOURCE" source || exit 1
echo

echo "2) Создание заказа (qty=1, preference=TIME)"
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-order" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":1,\"target_pvz_id\":\"$PVZ\",\"preference\":\"TIME\"}")
echo "$R"
OID=$(python3 -c 'import json,sys; order = json.load(sys.stdin)["data"]; assert order["scheme"] == "HUB_TRUNK"; print(order["id"])' <<< "$R") || exit 1

echo "3) Приёмка товара в московском ПВЗ для сборки и выдачи"
receive "$PVZ" target || exit 1
echo
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
