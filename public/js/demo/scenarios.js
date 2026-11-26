function scen() {
  seed();
  UI.log = [];
  UI.used = new Set();
  const P = (n) => DB.pvz.find((p) => p.name.includes(n)),
    I = (n) => DB.items.find((i) => i.name === n),
    k = uid;
  const st = (i, p) =>
      sum(
        DB.batches.filter((b) => b.item === i.id && b.pvz === p.id),
        (b) => b.qty,
      ),
    S = [],
    step = (a, t, ok, d) => a.push({ t, ok, d });
  const m2 = P('Москва-2'),
    m3 = P('Москва-3'),
    spb = P('СПб'),
    cup = I('Кружка'),
    jk = I('Куртка'),
    pl = I('Плед');
  let a = [],
    b0 = st(cup, m2);
  let r = call(
      'POST',
      '/orders',
      { item_id: cup.id, qty: 2, target_pvz_id: m3.id, preference: 'COST' },
      k(),
    ),
    o = r.data;
  step(
    a,
    `Заказ ${sh(o.id)} создан: ${o.chosen}, ${rub(o.cost)}, ${Number(o.hours).toFixed(1)} ч`,
    o.chosen === 'MESH',
    'Внутри города mesh дешевле хаба (' + rub(o.base) + '). ' + o.reason,
  );
  const key = k();
  call('POST', `/orders/${o.id}/assemble`, {}, key);
  const r2 = call('POST', `/orders/${o.id}/assemble`, {}, key);
  const r3 = call('POST', `/orders/${o.id}/assemble`, {}, k());
  step(
    a,
    'Сборка идемпотентна: повтор с тем же ключом и с новым ключом',
    st(cup, m2) === b0 - 2 && r2.replay && r3.data.replay,
    `Остаток ${m2.name}: до ${b0}, после ${st(cup, m2)} (списано один раз)`,
  );
  call('POST', `/orders/${o.id}/issue`, {}, k());
  step(
    a,
    'Выдача покупателю',
    o.status === 'ISSUED',
    'Статус: ' + o.status + ', выручка ' + rub(o.price),
  );
  S.push({ t: '1. Городской заказ', st: a });
  a = [];
  r = call(
    'POST',
    '/orders',
    { item_id: jk.id, qty: 10, target_pvz_id: spb.id, preference: 'TIME' },
    k(),
  );
  const h = r.data;
  step(
    a,
    `Заказ ${sh(h.id)}: ${h.chosen}, ${rub(h.cost)}, ${h.hours.toFixed(1)} ч`,
    h.chosen === 'HUB_TRUNK',
    'Mesh: ' + rub(h.variants.mesh.cost) + ' · хаб: ' + rub(h.variants.hub.cost),
  );
  step(
    a,
    'Вызваны заглушки хаба и магистрали',
    UI.used.has('POST /stub/hub/handle') && UI.used.has('POST /stub/trunk/move'),
    'Магистраль = chargeable_kg × км × 0.12, ставка — допущение',
  );
  S.push({ t: '2. Хаб и магистраль', st: a });
  a = [];
  const rb = st(cup, m3);
  call('POST', `/orders/${o.id}/return`, {}, k());
  step(
    a,
    'Возврат выданного заказа',
    o.status === 'RETURNED',
    `Остаток ${m3.name}: до ${rb}, после ${st(cup, m3)}; сторно выдачи ${rub(-pv(m3.id).fis)}`,
  );
  step(
    a,
    'Повторный возврат отклонён',
    call('POST', `/orders/${o.id}/return`, {}, k()).status === 409,
    'Статус-гард',
  );
  S.push({ t: '3. Возврат', st: a });
  a = [];
  r = call(
    'POST',
    '/orders',
    { item_id: cup.id, qty: 1, target_pvz_id: m3.id, preference: 'COST' },
    k(),
  );
  const e = r.data,
    c0 = e.cost;
  const ri = call('POST', '/incidents', { order_id: e.id, type: 'WRONG_PVZ' }, k());
  step(
    a,
    `Ошибка по заказу ${sh(e.id)}: ${ri.data.from} → ${ri.data.to}`,
    ri.status === 200,
    `Стоимость ${rub(c0)} → ${rub(e.cost)} (+${rub(ri.data.extra_cost)}), срок после переназначения: ${e.hours.toFixed(1)} ч`,
  );
  step(
    a,
    'Уведомление об ошибке на ПВЗ',
    DB.notes.some((n) => n.type === 'ERROR'),
    'Раздел «Уведомления»',
  );
  S.push({ t: '4. Логистическая ошибка', st: a });
  a = [];
  call('POST', `/pvz/${m2.id}/intake`, { item_id: pl.id, qty: 5 }, k());
  const l0 = sum(
    DB.ledger.filter((l) => l.kind === 'storage'),
    (l) => l.amt,
  );
  call('POST', '/system/time/advance', { days: 10 }, k());
  const l1 = sum(
    DB.ledger.filter((l) => l.kind === 'storage'),
    (l) => l.amt,
  );
  step(a, 'Время сдвинуто на 10 суток', DB.off === 10 * DAY, 'Системное время: ' + fd(now()));
  step(a, 'Начислено хранение', l1 > l0, `Хранение: до ${rub(l0)}, после ${rub(l1)}`);
  step(
    a,
    'Уведомление «долгое хранение»',
    DB.notes.some((n) => n.type === 'LONG_STORAGE'),
    'Партии старше 7 суток',
  );
  S.push({ t: '5. Длительное хранение', st: a });
  return S;
}
