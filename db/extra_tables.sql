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
