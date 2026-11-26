#!/usr/bin/env bash
# Сценарий 3: возврат заказа.
# Полный цикл до COMPLETED, затем возврат с причиной; проверка REFUND-проводки в аналитике ПВЗ.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000001"
SELLER="00000000-0000-0000-0000-5e1100000001"
PVZ="00000000-0000-0000-0000-f00d00000001"
SOURCE="00000000-0000-0000-0000-f00d00000002"
K="s3-$TS"

echo "== Сценарий 3: возврат =="
echo "1) Подготовка: приёмка, заказ, сборка, выдача"
SUPPLY=$(curl -fsS "$API/supplies" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-supply" \
    -d "{\"seller_id\":\"$SELLER\",\"item_id\":\"$ITEM\",\"pvz_id\":\"$SOURCE\",\"qty\":3}") || exit 1
SID=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])' <<< "$SUPPLY") || exit 1
curl -fsS -X POST "$API/supplies/$SID/accept" -H "Idempotency-Key: $K-intake" >/dev/null || exit 1
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-order" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":1,\"target_pvz_id\":\"$PVZ\",\"preference\":\"COST\"}")
OID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])')
curl -sS -X POST "$API/orders/$OID/assemble" -H "Idempotency-Key: $K-asm" >/dev/null
curl -sS -X POST "$API/orders/$OID/issue" -H "Idempotency-Key: $K-issue" >/dev/null
echo "   заказ $OID выдан (COMPLETED)"

echo "2) Возврат с причиной"
curl -sS -X POST "$API/orders/$OID/return" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-ret" \
    -d '{"reason":"покупатель отказался"}'; echo

echo "3) Аналитика ПВЗ: ожидаем REFUND в сборах"
curl -sS "$API/analytics/pvz/$PVZ" | python3 -c '
import sys, json
d = json.load(sys.stdin)["data"]
print(json.dumps(d["fees"], ensure_ascii=False, indent=2))'

echo "4) Уведомления ПВЗ: ожидаем RETURN"
curl -sS "$API/notifications?pvz_id=$PVZ" | python3 -c '
import sys, json
for n in json.load(sys.stdin)["data"][:3]:
    print(n["type"], "—", n["message"])'
echo "== Сценарий 3 завершён: order_id=$OID =="
