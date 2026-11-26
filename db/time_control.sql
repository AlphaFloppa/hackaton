-- Служебная таблица тестового времени (сценарий 5 — длительное хранение)
CREATE TABLE IF NOT EXISTS public.time_control (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Демо-справочники (заглушки по спеке)
INSERT INTO public.cities (id, name, code) VALUES
  ('11111111-1111-1111-1111-111111111111', 'Москва', 'MSK'),
  ('22222222-2222-2222-2222-222222222222', 'Санкт-Петербург', 'SPB')
ON CONFLICT (code) DO NOTHING;

INSERT INTO public.sellers (id, name, city_id) VALUES
  ('aaaaaaaa-1111-1111-1111-111111111111', 'Продавец-1', '11111111-1111-1111-1111-111111111111')
ON CONFLICT (id) DO NOTHING;
