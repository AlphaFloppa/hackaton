#!/usr/bin/env bash
# Сценарий 3: возврат заказа.
# Полный цикл до COMPLETED, затем возврат с причиной; проверка REFUND-проводки в аналитике ПВЗ.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000001"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-Москва-1' LIMIT 1" | tr -d '[:space:]')
K="s3-$TS"

echo "== Сценарий 3: возврат =="
echo "1) Подготовка: приёмка, заказ, сборка, выдача"
curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-intake" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":3,\"storage_tariff_day\":5}" >/dev/null
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
