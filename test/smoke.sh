#!/usr/bin/env bash
# Smoke-тест: быстрый прогон основных эндпоинтов API v1.
# Использование: bash test/smoke.sh [BASE_URL]
set -uo pipefail

API="${1:-${API:-http://127.0.0.1:8000}}/api/v1"
PASS=0; FAIL=0

# check NAME EXPECTED_SUBSTRING ACTUAL — проверка вхождения подстроки в ответ
check() {
    local name="$1" expected="$2" actual="$3"
    if [[ "$actual" == *"$expected"* ]]; then
        echo "PASS: $name"; PASS=$((PASS+1))
    else
        echo "FAIL: $name"; echo "  ожидалось вхождение: $expected"; echo "  ответ: $(echo "$actual" | head -c 300)"; FAIL=$((FAIL+1))
    fi
}

# --- health ---
R=$(curl -sS "$API/health")
check "GET /health" '"status":"ok"' "$R"

# --- фикстуры: товар и ПВЗ ---
ITEM="cccccccc-1111-1111-1111-111111111111"
SELLER="aaaaaaaa-1111-1111-1111-111111111111"
PVZ=$(sudo -u postgres psql -d wildb -tAc "SELECT id FROM pvz WHERE name='ПВЗ-1' LIMIT 1" | tr -d '[:space:]')
[[ -n "$PVZ" ]] || { echo "FAIL: ПВЗ-1 не найден, загрузите test/fixtures.sql"; exit 1; }

# --- создание товара ---
R=$(curl -sS "$API/items" -H 'Content-Type: application/json' \
    -d "{\"seller_id\":\"$SELLER\",\"sku\":\"SMOKE-$(date +%s)\",\"title\":\"Smoke\",\"weight_kg\":1,\"volume_m3\":0.01}")
check "POST /items" '"id"' "$R"

# --- приёмка ---
R=$(curl -sS "$API/pvz/$PVZ/intake" -H 'Content-Type: application/json' -H "Idempotency-Key: smoke-intake-$(date +%s)" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":5,\"storage_tariff_day\":5}")
check "POST /pvz/{id}/intake" 'batch_id' "$R"

# --- заказ ---
R=$(curl -sS "$API/orders" -H 'Content-Type: application/json' -H "Idempotency-Key: smoke-order-$(date +%s)" \
    -d "{\"item_id\":\"$ITEM\",\"qty\":1,\"target_pvz_id\":\"$PVZ\",\"preference\":\"COST\"}")
check "POST /orders" '"scheme"' "$R"
OID=$(echo "$R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])' 2>/dev/null || true)

# --- сборка / выдача / возврат ---
R=$(curl -sS -X POST "$API/orders/$OID/assemble" -H "Idempotency-Key: smoke-asm-$(date +%s)")
check "POST /orders/{id}/assemble" 'ASSEMBLED' "$R"
R=$(curl -sS -X POST "$API/orders/$OID/issue" -H "Idempotency-Key: smoke-issue-$(date +%s)")
check "POST /orders/{id}/issue" 'COMPLETED' "$R"

# --- чтение ---
R=$(curl -sS "$API/orders/$OID")
check "GET /orders/{id}" 'COMPLETED' "$R"
R=$(curl -sS "$API/items/$ITEM/batches")
check "GET /items/{id}/batches" 'batch_id' "$R"
R=$(curl -sS "$API/pvz/$PVZ/stock")
check "GET /pvz/{id}/stock" 'capacity_units' "$R"
R=$(curl -sS "$API/analytics/seller/$SELLER")
check "GET /analytics/seller/{id}" 'economics' "$R"
R=$(curl -sS "$API/analytics/pvz/$PVZ")
check "GET /analytics/pvz/{id}" 'storage' "$R"
R=$(curl -sS "$API/notifications?pvz_id=$PVZ")
check "GET /notifications" '"data"' "$R"

# --- время ---
R=$(curl -sS "$API/system/time/advance" -H 'Content-Type: application/json' -H "Idempotency-Key: smoke-t-$(date +%s)" -d '{"hours":2}')
check "POST /system/time/advance" 'hours_added' "$R"
R=$(curl -sS -X POST "$API/system/time/reset" -H "Idempotency-Key: smoke-tr-$(date +%s)")
check "POST /system/time/reset" 'now_override' "$R"

# --- ошибки ---
R=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API/orders/$OID/assemble")
check "POST без Idempotency-Key -> 400" '400' "$R"
R=$(curl -sS "$API/orders/11111111-2222-3333-4444-555555555555" -o /dev/null -w '%{http_code}')
check "GET несуществующий заказ -> 404" '404' "$R"

echo
echo "Итог: $PASS pass, $FAIL fail"
exit $(( FAIL > 0 ))
