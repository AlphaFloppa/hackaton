-- Таблицы, которых нет в wildb, но нужны бэкенду (создаёт hackaton-back)

CREATE TABLE IF NOT EXISTS public.notifications (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pvz_id     UUID REFERENCES public.pvz(id),
    order_id   UUID REFERENCES public.orders(id),
    type       TEXT NOT NULL,
    message    TEXT NOT NULL,
    is_read    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.incidents (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id    UUID REFERENCES public.orders(id),
    pvz_id      UUID REFERENCES public.pvz(id),
    type        TEXT NOT NULL,
    description TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.time_control (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Завоз партии от продавца: ПВЗ может принять товар только по существующему завозу
CREATE TABLE IF NOT EXISTS public.supplies (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    seller_id       UUID NOT NULL REFERENCES public.sellers(id),
    item_id         UUID NOT NULL REFERENCES public.items(id),
    pvz_id          UUID NOT NULL REFERENCES public.pvz(id),
    qty             INTEGER NOT NULL CHECK (qty > 0),
    status          TEXT NOT NULL DEFAULT 'CREATED'
                    CHECK (status = ANY (ARRAY['CREATED'::text, 'ACCEPTED'::text, 'CANCELLED'::text])),
    idempotency_key TEXT UNIQUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS supplies_pvz_idx ON public.supplies (pvz_id, status);
