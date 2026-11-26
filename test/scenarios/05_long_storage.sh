#!/usr/bin/env bash
# Сценарий 5: длительное хранение.
# Приёмка партии -> сдвиг тестового времени на 10 суток -> начисление хранения -> сброс времени.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

ITEM="00000000-0000-0000-0000-17e500000001"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-Москва-1' LIMIT 1" | tr -d '[:space:]')
K="s5-$TS"

echo "== Сценарий 5: длительное хранение =="
echo "1) Приёмка партии (5 ед. по 5₽/ед./сутки)"
curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-intake" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":5,\"storage_tariff_day\":5}"; echo

echo "2) Сдвиг тестового времени на 240 часов (10 суток)"
curl -sS "$API/system/time/advance" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-tadv" \
    -d '{"hours":240}'; echo

echo "3) Начисление хранения"
curl -sS -X POST "$API/pvz/$PVZ/storage/accrue" -H "Idempotency-Key: $K-accrue"; echo

echo "4) Аналитика ПВЗ: ожидаем FEE_STORAGE"
curl -sS "$API/analytics/pvz/$PVZ" | python3 -c '
import sys, json
d = json.load(sys.stdin)["data"]
print(json.dumps(d["fees"], ensure_ascii=False, indent=2))'

echo "5) Уведомления ПВЗ: ожидаем STORAGE"
curl -sS "$API/notifications?pvz_id=$PVZ" | python3 -c '
import sys, json
for n in json.load(sys.stdin)["data"][:3]:
    print(n["type"], "—", n["message"])'

echo "6) Сброс тестового времени"
curl -sS -X POST "$API/system/time/reset" -H "Idempotency-Key: $K-treset"; echo
echo "== Сценарий 5 завершён =="
