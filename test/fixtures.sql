-- Тестовые фикстуры для wildb (идемпотентно: ON CONFLICT DO NOTHING)
-- Загрузка: sudo -u postgres psql -d wildb < test/fixtures.sql

-- Города
INSERT INTO cities (id, name, code) VALUES
  ('11111111-1111-1111-1111-111111111111', 'Москва',          'MSK'),
  ('22222222-2222-2222-2222-222222222222', 'Санкт-Петербург', 'SPB')
ON CONFLICT (code) DO NOTHING;

-- ПВЗ: один в Москве (городской сценарий), один в СПб (межгород)
INSERT INTO pvz (id, city_id, name, address, lat, lng, capacity_units,
                 fee_intake, fee_assembly, fee_issue, fee_storage_day)
VALUES
  ('33333333-1111-1111-1111-111111111111', '11111111-1111-1111-1111-111111111111',
   'ПВЗ-1', 'ул. Тестовая 1', 55.751244, 37.618423, 100, 10.00, 20.00, 15.00, 5.00),
  ('33333333-2222-2222-2222-222222222222', '22222222-2222-2222-2222-222222222222',
   'ПВЗ-2', 'Невский пр. 1', 59.934280, 30.335099, 80, 12.00, 25.00, 18.00, 6.00)
ON CONFLICT (id) DO NOTHING;

-- Хаб (заглушка) в СПб для HUB_TRUNK-сценария
INSERT INTO hubs (id, city_id, name, is_stub)
VALUES ('44444444-2222-2222-2222-222222222222', '22222222-2222-2222-2222-222222222222', 'Хаб-СПб', true)
ON CONFLICT (id) DO NOTHING;

-- Продавцы
INSERT INTO sellers (id, name, city_id) VALUES
  ('aaaaaaaa-1111-1111-1111-111111111111', 'Продавец-1', '11111111-1111-1111-1111-111111111111'),
  ('aaaaaaaa-2222-2222-2222-222222222222', 'Продавец-2', '22222222-2222-2222-2222-222222222222')
ON CONFLICT (id) DO NOTHING;

-- Покупатель-заглушка
INSERT INTO customers_stub (id, city_id, label)
VALUES ('bbbbbbbb-1111-1111-1111-111111111111', '11111111-1111-1111-1111-111111111111', 'customer-001')
ON CONFLICT (id) DO NOTHING;

-- Товары (по одному на продавца)
INSERT INTO items (id, seller_id, sku, title, weight_kg, volume_m3) VALUES
  ('cccccccc-1111-1111-1111-111111111111', 'aaaaaaaa-1111-1111-1111-111111111111', 'TEST-MSK-1', 'Тестовый товар Москва', 1.500, 0.0100),
  ('cccccccc-2222-2222-2222-222222222222', 'aaaaaaaa-2222-2222-2222-222222222222', 'TEST-SPB-1', 'Тестовый товар СПб', 2.000, 0.0200)
ON CONFLICT (seller_id, sku) DO NOTHING;
