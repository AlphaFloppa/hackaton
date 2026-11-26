#!/usr/bin/env bash
# Сценарий 1: городской заказ (MESH).
# Городской заказ через городскую mesh-сеть: приёмка -> заказ -> сборка -> выдача.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000001"   # товар Продавца-1 (Москва)
SELLER="00000000-0000-0000-0000-5e1100000001"
PVZ="00000000-0000-0000-0000-f00d00000001"
K="s1-$TS"   # префикс Idempotency-Key (перезапуск сценария безопасен)

echo "== Сценарий 1: городской заказ =="
echo "1) Завоз и приёмка партии на ПВЗ-1 (5 ед.)"
SUPPLY=$(curl -fsS "$API/supplies" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-supply" \
    -d "{\"seller_id\":\"$SELLER\",\"item_id\":\"$ITEM\",\"pvz_id\":\"$PVZ\",\"qty\":5}") || exit 1
SID=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])' <<< "$SUPPLY") || exit 1
curl -fsS -X POST "$API/supplies/$SID/accept" -H "Idempotency-Key: $K-intake" || exit 1
echo

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
