#!/usr/bin/env bash
# Сценарий 5: длительное хранение.
# Завоз -> приёмка -> сдвиг тестового времени и начисление хранения -> сброс времени.
set -euo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"

ITEM="00000000-0000-0000-0000-17e500000001"
SELLER="00000000-0000-0000-0000-5e1100000001"
PVZ="00000000-0000-0000-0000-f00d00000001"
K="s5-$(date +%s)-$$"

post() {
    local path="$1" key="$2"
    shift 2
    curl -fsS -X POST "$API$path" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-$key" "$@"
}

trap 'post /system/time/reset reset >/dev/null' EXIT
post /system/time/reset start >/dev/null

echo "1) Завоз и приёмка партии (5 ед.)"
SUPPLY=$(post /supplies supply -d "{\"seller_id\":\"$SELLER\",\"item_id\":\"$ITEM\",\"pvz_id\":\"$PVZ\",\"qty\":5}")
SID=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])' <<< "$SUPPLY")
RECEIPT=$(post "/supplies/$SID/accept" accept)
BATCH=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["batch_id"])' <<< "$RECEIPT")
BEFORE=$(curl -fsS "$API/frontend/analytics/pvz/$PVZ")

echo "2) Сдвиг на 10 суток с начислением хранения; повтор с тем же ключом"
PAYLOAD='{"hours":240,"accrue_storage":true}'
SHIFT=$(post /system/time/advance advance -d "$PAYLOAD")
[[ $(post /system/time/advance advance -d "$PAYLOAD") == "$SHIFT" ]]

echo "3) Проверка времени и возраста партии в интерфейсе"
BOOTSTRAP=$(curl -fsS "$API/frontend/bootstrap")
BATCHES=$(curl -fsS "$API/frontend/items/$ITEM/batches")
python3 -c '
import json, sys
shift, bootstrap, batches = map(json.loads, sys.argv[1:])
assert bootstrap["now"] == shift["data"]["now_override"]
assert any(row["pvz"] == "ПВЗ-Москва-1" and row["age_days"] == 10 for row in batches)
' "$SHIFT" "$BOOTSTRAP" "$BATCHES"

echo "4) Аналитика, одно уведомление по партии и отсутствие повторного начисления"
AFTER=$(curl -fsS "$API/frontend/analytics/pvz/$PVZ")
NOTES=$(curl -fsS "$API/frontend/notifications?pvz_id=$PVZ")
REPLAY=$(post "/pvz/$PVZ/storage/accrue" recheck)
python3 -c '
import json, sys
before, after, notes, replay = map(json.loads, sys.argv[1:5])
assert after["storage"] - before["storage"] >= 75
assert sum(row["type"] == "LONG_STORAGE" and sys.argv[5] in row["text"] for row in notes) == 1
assert replay["data"]["entries"] == 0
' "$BEFORE" "$AFTER" "$NOTES" "$REPLAY" "$BATCH"

echo "5) Сброс тестового времени"
post /system/time/reset reset | python3 -c 'import json,sys; assert json.load(sys.stdin)["data"]["now_override"] is None'
echo "== Сценарий 5 завершён =="
