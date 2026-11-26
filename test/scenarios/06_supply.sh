#!/usr/bin/env bash
# Сценарий 6: завоз и приёмка партии.
# Продавец объявляет завоз на ПВЗ -> ПВЗ принимает -> товар появляется на остатках.
# Без завоза приёмка невозможна; вместимость ПВЗ ограничивает завоз.
set -uo pipefail
API="${API:-http://127.0.0.1:8000}/api/v1"
TS=$(date +%s)

SELLER="00000000-0000-0000-0000-5e1100000001"
ITEM="00000000-0000-0000-0000-17e500000002"   # фонарик
PVZ="00000000-0000-0000-0000-f00d00000002"
K="s6-$TS"

echo "== Сценарий 6: завоз и приёмка =="
echo "1) Продавец объявляет завоз 5 фонариков на ПВЗ-Москва-2"
R=$(curl -sS "$API/supplies" -H 'Content-Type: application/json' -H "Idempotency-Key: $K-sup" \
    -d "{\"seller_id\":\"$SELLER\",\"item_id\":\"$ITEM\",\"pvz_id\":\"$PVZ\",\"qty\":5}")
echo "$R"
SID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])')

echo "2) ПВЗ принимает завоз (партия создана)"
curl -sS -X POST "$API/supplies/$SID/accept" -H "Idempotency-Key: $K-acc"; echo

echo "3) Повторная приёмка того же завоза (ожидаем already_processed)"
curl -sS -X POST "$API/supplies/$SID/accept" -H "Idempotency-Key: $K-acc"; echo

echo "4) Остатки ПВЗ: фонарик на месте"
curl -sS "$API/pvz/$PVZ/stock" | python3 -c '
import sys, json
d = json.load(sys.stdin)["data"]
print("load:", d["pvz"]["used_units"], "/", d["pvz"]["capacity_units"])
for r in d["batches"]:
    if r["title"] == "Фонарик":
        print("  Фонарик:", r["qty_available"], "шт.")'

echo "5) Список завозов ПВЗ"
curl -sS "$API/supplies?pvz_id=$PVZ" | python3 -c '
import sys, json
for s in json.load(sys.stdin)["data"][:3]:
    print("  ", s["status"], s["item_title"], "×", s["qty"], "·", s["seller_name"])'
echo "== Сценарий 6 завершён: supply_id=$SID =="
