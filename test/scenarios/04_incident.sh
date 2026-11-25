#!/usr/bin/env bash
# Сценарий 4: логистическая ошибка (инцидент).
# Заказ в пути -> инцидент MISSEND -> уведомление ПВЗ.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="cccccccc-2222-2222-2222-222222222222"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-2' LIMIT 1" | tr -d '[:space:]')
K="s4-$TS"

echo "== Сценарий 4: логистическая ошибка =="
echo "1) Подготовка: приёмка, заказ, сборка"
curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-intake" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":2,\"storage_tariff_day\":6}" >/dev/null
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-order" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":1,\"target_pvz_id\":\"$PVZ\",\"preference\":\"TIME\"}")
OID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])')
curl -sS -X POST "$API/orders/$OID/assemble" -H "Idempotency-Key: $K-asm" >/dev/null
echo "   заказ $OID собран (ASSEMBLED)"

echo "2) Фиксация инцидента MISSEND"
curl -sS "$API/incidents" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-inc" \
    -d "{\"order_id\":\"$OID\",\"pvz_id\":\"$PVZ\",\"type\":\"MISSEND\",\"description\":\"Посылка ушла не в тот хаб\"}"; echo

echo "3) Уведомления ПВЗ: ожидаем INCIDENT"
curl -sS "$API/notifications?pvz_id=$PVZ" | python3 -c '
import sys, json
for n in json.load(sys.stdin)["data"][:3]:
    print(n["type"], "—", n["message"])'
echo "== Сценарий 4 завершён: order_id=$OID =="
