CREATE TABLE cities (
    id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),   -- ID города
    name TEXT NOT NULL,                                -- название ("Москва")
    code TEXT NOT NULL UNIQUE                          -- код (MSK, SPB)
);

-- ПВЗ (пункт выдачи заказов)
CREATE TABLE pvz (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID ПВЗ
    city_id         UUID NOT NULL REFERENCES cities(id),         -- город ПВЗ
    name            TEXT NOT NULL,                               -- название/номер ПВЗ
    address         TEXT NOT NULL,                               -- адрес
    lat             NUMERIC(9,6) NOT NULL,                       -- широта (для графа)
    lng             NUMERIC(9,6) NOT NULL,                       -- долгота (для графа)
    capacity_units  INTEGER NOT NULL,                            -- вместимость, ед. хранения
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,               -- доступен ли ПВЗ
    fee_intake      NUMERIC(12,2) NOT NULL DEFAULT 0,            -- вознаграждение за приёмку, ₽/ед.
    fee_assembly    NUMERIC(12,2) NOT NULL DEFAULT 0,            -- вознаграждение за сборку, ₽/заказ
    fee_issue       NUMERIC(12,2) NOT NULL DEFAULT 0,            -- вознаграждение за выдачу, ₽/заказ
    fee_storage_day NUMERIC(12,2) NOT NULL DEFAULT 0,            -- тариф хранения, ₽/ед./сутки
    sla_hours_city  INTEGER NOT NULL DEFAULT 24,                 -- SLA по городу, часы
    sla_hours_inter INTEGER NOT NULL DEFAULT 72                  -- SLA межгород, часы
);

CREATE TABLE hubs (
    id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID хаба
    city_id UUID NOT NULL REFERENCES cities(id),         -- город хаба
    name    TEXT NOT NULL,                               -- название хаба
    is_stub BOOLEAN NOT NULL DEFAULT TRUE                -- всегда TRUE (заглушка)
);

-- Продавцы
CREATE TABLE sellers (
    id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID продавца
    name    TEXT NOT NULL,                               -- название/имя
    city_id UUID REFERENCES cities(id)                   -- город продавца (может быть NULL)
);

-- Покупатели (STUB: только запись, без ЛК)
CREATE TABLE customers_stub (
    id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID покупателя-заглушки
    city_id UUID REFERENCES cities(id),                  -- город покупателя
    label   TEXT NOT NULL                                -- метка ("customer-001")
);

-- Товар (карточка)
CREATE TABLE items (
    id        UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID товара
    seller_id UUID NOT NULL REFERENCES sellers(id),        -- владелец-продавец
    sku       TEXT NOT NULL,                               -- артикул продавца
    title     TEXT NOT NULL,                               -- название
    weight_kg NUMERIC(10,3) NOT NULL DEFAULT 0,            -- вес, кг (для перевозки)
    volume_m3 NUMERIC(10,4) NOT NULL DEFAULT 0,            -- объём, м³ (для хранения)
    UNIQUE (seller_id, sku)                                -- артикул уникален в рамках продавца
);

-- Партия товара, принятая на конкретный ПВЗ (функция А)
CREATE TABLE stock_batches (
    id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID партии
    item_id            UUID NOT NULL REFERENCES items(id),          -- товар
    pvz_id             UUID NOT NULL REFERENCES pvz(id),            -- где хранится
    qty_received       INTEGER NOT NULL CHECK (qty_received > 0),   -- сколько принято
    qty_available      INTEGER NOT NULL CHECK (qty_available >= 0), -- доступно к резерву
    qty_reserved       INTEGER NOT NULL DEFAULT 0 CHECK (qty_reserved >= 0), -- зарезервировано
    received_at        TIMESTAMPTZ NOT NULL DEFAULT now(),          -- дата приёмки (для хранения)
    storage_tariff_day NUMERIC(12,2) NOT NULL DEFAULT 0,            -- тариф на момент приёмки, ₽/ед./сутки
    status             TEXT NOT NULL DEFAULT 'ON_STORAGE'           -- состояние партии
                       CHECK (status IN ('ON_STORAGE','IN_TRANSIT','DELIVERED','RETURNED','WRITTEN_OFF')),
    CHECK (qty_available + qty_reserved <= qty_received)            -- баланс остатков
);

-- Движения остатков (аудит + идемпотентность)
CREATE TABLE stock_movements (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID движения
    batch_id        UUID NOT NULL REFERENCES stock_batches(id),  -- партия
    order_id        UUID,                                        -- связанный заказ (если есть)
    type            TEXT NOT NULL                                -- тип операции
                    CHECK (type IN ('INTAKE','RESERVE','RELEASE','COMMIT','RETURN','TRANSFER','WRITE_OFF')),
    qty             INTEGER NOT NULL,                            -- количество (знак зависит от типа)
    idempotency_key TEXT UNIQUE,                                 -- защита от двойного списания
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()           -- когда
);

-- Заказ (функция Б)
CREATE TABLE orders (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID заказа
    seller_id       UUID NOT NULL REFERENCES sellers(id),        -- продавец
    item_id         UUID NOT NULL REFERENCES items(id),          -- товар
    qty             INTEGER NOT NULL CHECK (qty > 0),            -- количество
    customer_id     UUID REFERENCES customers_stub(id),          -- покупатель (STUB)
    target_pvz_id   UUID NOT NULL REFERENCES pvz(id),            -- ПВЗ выдачи
    source_batch_id UUID REFERENCES stock_batches(id),           -- откуда берём (для mesh)
    scheme          TEXT CHECK (scheme IN ('MESH','HUB_TRUNK')), -- выбранная схема
    status          TEXT NOT NULL DEFAULT 'CREATED'              -- статус заказа
                    CHECK (status IN ('CREATED','RESERVED','ASSEMBLED','IN_TRANSIT',
                                      'DELIVERED_TO_PVZ','COMPLETED','RETURNED','FAILED')),
    delivery_cost   NUMERIC(12,2),                               -- стоимость доставки для покупателя
    expected_hours  NUMERIC(10,2),                               -- ожидаемый срок, часы
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),          -- создан
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()           -- обновлён
);

-- Сегменты маршрута (результат routing)
CREATE TABLE order_segments (
    id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID сегмента
    order_id       UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE, -- заказ
    seq            INTEGER NOT NULL,                            -- порядок сегмента
    mode           TEXT NOT NULL                                -- тип сегмента
                   CHECK (mode IN ('LOCAL_MESH','SELLER_TO_HUB','TRUNK','LAST_MILE')),
    from_node      TEXT NOT NULL,                               -- узел отправления
    to_node        TEXT NOT NULL,                               -- узел назначения
    distance_km    NUMERIC(10,2) NOT NULL DEFAULT 0,            -- расстояние, км
    time_min       NUMERIC(10,2) NOT NULL DEFAULT 0,            -- время, мин
    cost_transport NUMERIC(12,2) NOT NULL DEFAULT 0,            -- перевозка, ₽
    cost_handling  NUMERIC(12,2) NOT NULL DEFAULT 0,            -- обработка, ₽
    cost_hub_fee   NUMERIC(12,2) NOT NULL DEFAULT 0,            -- тариф хаба, ₽ (STUB)
    cost_risk      NUMERIC(12,2) NOT NULL DEFAULT 0,            -- ожидаемые потери/штрафы, ₽
    is_stub        BOOLEAN NOT NULL DEFAULT FALSE,              -- сегмент-заглушка (хаб/магистраль)
    breakdown      JSONB NOT NULL DEFAULT '{}',                 -- разложение по статьям
    UNIQUE (order_id, seq)                                      -- один сегмент на позицию
);

-- Решение маршрутизации с обоснованием (требование функции В)
CREATE TABLE routing_decisions (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID решения
    order_id   UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE, -- заказ
    scheme     TEXT NOT NULL CHECK (scheme IN ('MESH','HUB_TRUNK')),  -- выбранная схема
    reason     TEXT NOT NULL,                               -- почему выбрана ("нет локального остатка", "переполнен ПВЗ")
    cost_mesh  NUMERIC(12,2),                               -- стоимость альтернативы mesh
    cost_hub   NUMERIC(12,2),                               -- стоимость альтернативы хаб+магистраль
    time_mesh  NUMERIC(10,2),                               -- срок альтернативы mesh
    time_hub   NUMERIC(10,2),                               -- срок альтернативы хаб+магистраль
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()           -- когда решено
);

-- Снимок расчёта по заказу (для аналитики и «до/после»)
CREATE TABLE order_calcs (
    id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID расчёта
    order_id       UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE, -- заказ
    scheme         TEXT NOT NULL,                               -- схема расчёта
    buyer_price    NUMERIC(12,2) NOT NULL DEFAULT 0,            -- цена для покупателя
    buyer_hours    NUMERIC(10,2) NOT NULL DEFAULT 0,            -- срок для покупателя, часы
    seller_revenue NUMERIC(12,2) NOT NULL DEFAULT 0,            -- выручка продавца (STUB)
    seller_costs   NUMERIC(12,2) NOT NULL DEFAULT 0,            -- затраты продавца
    seller_result  NUMERIC(12,2) NOT NULL DEFAULT 0,            -- результат продавца
    mp_revenue     NUMERIC(12,2) NOT NULL DEFAULT 0,            -- доход маркетплейса
    mp_costs       NUMERIC(12,2) NOT NULL DEFAULT 0,            -- затраты маркетплейса
    mp_result      NUMERIC(12,2) NOT NULL DEFAULT 0,            -- результат маркетплейса
    pvz_revenue    NUMERIC(12,2) NOT NULL DEFAULT 0,            -- доход ПВЗ
    pvz_costs      NUMERIC(12,2) NOT NULL DEFAULT 0,            -- затраты ПВЗ
    pvz_result     NUMERIC(12,2) NOT NULL DEFAULT 0,            -- результат ПВЗ
    breakdown      JSONB NOT NULL DEFAULT '{}',                 -- разложение по статьям
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()           -- когда рассчитано
);

-- Ledger: начисления/удержания (без реальных денег)
CREATE TABLE ledger_entries (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),  -- ID записи
    order_id        UUID REFERENCES orders(id),                  -- связанный заказ
    pvz_id          UUID REFERENCES pvz(id),                     -- ПВЗ (если применимо)
    seller_id       UUID REFERENCES sellers(id),                 -- продавец (если применимо)
    party           TEXT NOT NULL                                -- кому начисление
                    CHECK (party IN ('PVZ','SELLER','MARKETPLACE','BUYER')),
    entry_type      TEXT NOT NULL,                               -- тип: STORAGE, INTAKE, ASSEMBLY, ISSUE, HUB_FEE, TRUNK, RETURN, PENALTY, PAYOUT...
    amount          NUMERIC(12,2) NOT NULL,                      -- сумма: + доход, - расход
    reason          TEXT,                                        -- пояснение
    idempotency_key TEXT UNIQUE,                                 -- защита от двойного начисления
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()           -- когда
);

