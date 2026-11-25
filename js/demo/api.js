const H = [
  [
    'GET',
    '/health',
    () => ({ status: 'ok', db: 'postgres16 (mock)', time: new Date(now()).toISOString() }),
  ],
  [
    'POST',
    '/items',
    (b) => {
      if (!String(b.name ?? '').trim()) throw E(422, 'Укажите название');
      if (
        ![b.kg, b.vol, b.price].every(
          (value) => String(value ?? '').trim() && Number.isFinite(+value),
        ) ||
        +b.kg <= 0 ||
        +b.vol <= 0 ||
        +b.price < 0
      ) {
        throw E(422, 'Вес и объём должны быть больше нуля, цена — от нуля');
      }
      const i = {
        id: uid(),
        name: b.name.trim(),
        kg: +b.kg,
        vol: +b.vol,
        price: +b.price,
      };
      DB.items.push(i);
      return i;
    },
  ],
  [
    'GET',
    '/items/:id/batches',
    (b, id) =>
      DB.batches
        .filter((x) => x.item === id && x.qty > 0)
        .map((x) => ({
          pvz: pv(x.pvz).name,
          qty: x.qty,
          reserved: x.res,
          age_days: Math.floor((now() - x.at) / DAY),
        })),
  ],
  ['POST', '/orders', createOrder],
  [
    'GET',
    '/orders/:id',
    (b, id) => {
      const order = od(id);
      const revenue = order.status === 'ISSUED' ? order.price : 0;
      const feeLines = DB.ledger
        .filter((entry) => entry.oid === id && entry.pvz)
        .map((entry) => ({ label: entry.why, amount: entry.amt }));
      const pvz_fees = sum(feeLines, (entry) => entry.amount);
      const incidentFee = sum(order.incidents, (incident) => incident.extra_cost);
      const cost_lines = [
        { label: 'Логистика по выбранной схеме', amount: order.cost - incidentFee },
        ...(incidentFee ? [{ label: 'Доплата из-за инцидента', amount: incidentFee }] : []),
        ...feeLines,
      ];
      return {
        ...order,
        financials: {
          revenue,
          pvz_fees,
          result: revenue - order.cost - pvz_fees,
          cost_lines,
        },
      };
    },
  ],
  [
    'GET',
    '/analytics/seller/:id',
    () => {
      accrue();
      const os = DB.orders,
        rev = sum(
          os.filter((o) => o.status === 'ISSUED'),
          (o) => o.price,
        ),
        lg = sum(os, (o) => o.cost),
        fees = sum(
          DB.ledger.filter((entry) => entry.pvz),
          (l) => l.amt,
        ),
        base = sum(os, (o) => o.base),
        m = lg;
      return {
        orders: os.length,
        revenue: rev,
        logistics: lg,
        pvz_fees: fees,
        profit: rev - lg - fees,
        base_cost: base,
        mesh_cost: m,
        saving: base - m,
        saving_pct: base ? ((base - m) / base) * 100 : 0,
      };
    },
  ],
  [
    'POST',
    '/pvz/:id/intake',
    (b, id) => {
      const p = pv(id),
        i = it(b.item_id),
        q = +b.qty;
      if (!p?.active || !i || !Number.isSafeInteger(q) || q < 1)
        throw E(422, 'Проверьте товар и количество');
      if (load(id) + q > p.cap)
        throw E(409, `Нет места: ${load(id)}/${p.cap}, приёмка ${q} ед.`);
      DB.batches.push({
        id: uid(),
        item: i.id,
        pvz: id,
        qty: q,
        res: 0,
        at: now(),
        chg: now(),
      });
      led('intake', id, p.fi * q, `Приёмка ${q} ед. «${i.name}»`);
      return { accepted: q, load: load(id), capacity: p.cap };
    },
  ],
  [
    'GET',
    '/pvz/:id/stock',
    (b, id) => ({
      load: load(id),
      capacity: pv(id).cap,
      rows: DB.batches
        .filter((x) => x.pvz === id && x.qty > 0)
        .map((x) => ({
          item: it(x.item).name,
          qty: x.qty,
          reserved: x.res,
          age_days: Math.floor((now() - x.at) / DAY),
        })),
    }),
  ],
  [
    'POST',
    '/orders/:id/assemble',
    (b, id) => {
      const o = od(id);
      if (o.status === 'ASSEMBLED' || o.status === 'ISSUED')
        return {
          order_id: id,
          status: o.status,
          replay: true,
          note: 'Уже собран — повторного списания нет',
        };
      if (o.status !== 'CREATED') throw E(409, 'Нельзя собрать заказ в статусе ' + o.status);
      const bt = DB.batches.find((x) => x.id === o.batch);
      bt.qty -= o.qty;
      bt.res -= o.qty;
      led('assembly', bt.pvz, pv(bt.pvz).fa, 'Сборка ' + sh(o.id), o.id);
      o.status = 'ASSEMBLED';
      o.history.push({ type: o.status, at: now() });
      return { order_id: id, status: o.status, written_off: o.qty };
    },
  ],
  [
    'POST',
    '/orders/:id/issue',
    (b, id) => {
      const o = od(id);
      if (o.status !== 'ASSEMBLED')
        throw E(409, 'Выдать можно только собранный заказ (сейчас ' + o.status + ')');
      led('issue', o.target, pv(o.target).fis, 'Выдача ' + sh(o.id), o.id);
      o.status = 'ISSUED';
      o.history.push({ type: o.status, at: now() });
      return { order_id: id, status: o.status, revenue: o.price };
    },
  ],
  [
    'POST',
    '/orders/:id/return',
    (b, id) => {
      const o = od(id);
      if (o.status !== 'ISSUED')
        throw E(409, 'Вернуть можно только выданный заказ (сейчас ' + o.status + ')');
      const p = pv(o.target);
      if (load(p.id) + o.qty > p.cap) throw E(409, 'Нет места для приёмки возврата');
      led('correction', p.id, -p.fis, 'Возврат: сторно выдачи ' + sh(o.id), o.id);
      DB.batches.push({
        id: uid(),
        item: o.item,
        pvz: p.id,
        qty: o.qty,
        res: 0,
        at: now(),
        chg: now(),
      });
      o.status = 'RETURNED';
      o.history.push({ type: o.status, at: now() });
      note(
        'INFO',
        p.id,
        'Возврат по заказу ' + sh(o.id) + ': ' + o.qty + ' ед. снова на остатке',
      );
      return { order_id: id, status: o.status, restocked: o.qty };
    },
  ],
  [
    'POST',
    '/incidents',
    (b) => {
      const o = od(b.order_id);
      if (o.status === 'ISSUED' || o.status === 'RETURNED')
        throw E(409, 'Заказ уже выдан — ошибка маршрута невозможна');
      if (o.incidents.length) throw E(409, 'Ошибка по этому заказу уже зарегистрирована');
      const alt = o.chosen === 'MESH' ? o.variants.hub : o.variants.mesh,
        extra = 30 + o.cost * 0.2,
        from = o.chosen;
      if (alt.ok) {
        o.chosen = alt.scheme;
        o.segs = alt.segs;
        o.cost = alt.cost;
        o.hours = alt.hours;
        o.reason = `После логистической ошибки схема изменена: ${from} → ${o.chosen}.`;
      }
      if (!alt.ok) o.reason = `Маршрут сохранён: ${alt.why}. Учтены последствия ошибки.`;
      o.cost += extra;
      o.hours += 6;
      led(
        'incident',
        null,
        extra,
        'Логистическая ошибка: ' + (b.type || 'WRONG_PVZ') + ' · ' + sh(o.id),
        o.id,
      );
      const inc = {
        id: uid(),
        order: o.id,
        type: b.type || 'WRONG_PVZ',
        from,
        to: o.chosen,
        extra_cost: extra,
        extra_hours: 6,
      };
      inc.at = now();
      o.incidents.push(inc);
      o.history.push({
        type: from === o.chosen ? 'INCIDENT' : 'ROUTE_CHANGED',
        at: inc.at,
        detail: `${from === o.chosen ? 'Схема сохранена' : from + ' → ' + o.chosen} · +${rub(inc.extra_cost)}, +${inc.extra_hours} ч`,
      });
      note(
        'ERROR',
        o.target,
        `Ошибка по заказу ${sh(o.id)}: маршрут ${from} → ${o.chosen}, +${rub(extra)}, +6 ч`,
      );
      return inc;
    },
  ],
  [
    'GET',
    '/analytics/pvz/:id',
    (b, id) => {
      accrue();
      const L = DB.ledger.filter((l) => l.pvz === id),
        inc = sum(L, (l) => l.amt),
        by = (k) =>
          sum(
            L.filter((l) => l.kind === k),
            (l) => l.amt,
          );
      return {
        income: inc,
        intake: by('intake'),
        assembly: by('assembly'),
        issue: by('issue'),
        storage: by('storage'),
        corrections: by('correction'),
        cost: inc * 0.4,
        profit: inc * 0.6,
        load: load(id),
        capacity: pv(id).cap,
      };
    },
  ],
  ['GET', '/notifications', (b) => DB.notes.filter((n) => !b.pvz || n.pvz === b.pvz)],
  [
    'POST',
    '/stub/hub/handle',
    (b) => ({
      is_stub: true,
      hub_fee: { handling_in: 15, sorting: 10, handling_out: 15, total: 40 },
      chargeable_kg: b.chargeable_kg,
    }),
  ],
  [
    'POST',
    '/stub/trunk/move',
    (b) => ({
      is_stub: true,
      cost: Math.max(30, b.chargeable_kg * b.km * 0.12),
      hours: 2 + b.km / 60,
      formula: 'chargeable_kg × km × 0.12',
    }),
  ],
  [
    'POST',
    '/system/time/advance',
    (b) => {
      const days = Number(b.days);
      if (!Number.isSafeInteger(days) || days < 1 || now() + days * DAY > 8.64e15)
        throw E(422, 'Укажите допустимое целое число дней от 1');
      DB.off += days * DAY;
      accrue();
      return { now: new Date(now()).toISOString() };
    },
  ],
];
const EPS = H.map((h) => h[0] + ' ' + h[1].replace(':id', '{id}'));
function accrue() {
  DB.batches.forEach((b) => {
    const d = Math.floor((now() - b.chg) / DAY);
    if (d > 0) {
      if (b.qty > 0) {
        const p = pv(b.pvz);
        led('storage', p.id, p.fs * b.qty * d, `Хранение ${esc(b.qty)} ед. × ${d} сут.`);
      }
      b.chg += d * DAY;
    }
    if (!b.warned && b.qty > 0 && now() - b.at >= 7 * DAY) {
      b.warned = 1;
      note(
        'LONG_STORAGE',
        b.pvz,
        `Партия «${it(b.item).name}» (${esc(b.qty)} ед.) лежит ${Math.floor((now() - b.at) / DAY)} сут.`,
      );
    }
  });
}
function call(m, path, body = {}, key) {
  let id,
    h = H.find((x) => {
      if (x[0] !== m) return 0;
      const r = new RegExp('^' + x[1].replace(':id', '([0-9a-f-]{36})') + '$').exec(path);
      if (r) {
        id = r[1];
        return 1;
      }
    });
  const ep = m + ' ' + (h ? h[1].replace(':id', '{id}') : path);
  const signature = JSON.stringify([
    m,
    path,
    Object.entries(body).sort(([a], [b]) => a.localeCompare(b)),
  ]);
  let st = 200,
    data,
    replay = 0;
  try {
    if (!h) throw E(404, 'Маршрут не найден');
    if (m === 'POST' && h[1] !== '/items') {
      if (!key) throw E(400, 'Idempotency-Key обязателен на POST');
      const previous = DB.idem.get(key);
      if (previous) {
        if (previous.signature !== signature)
          throw E(409, 'Этот ключ уже использован для другой операции');
        replay = 1;
        st = previous.status;
        data = structuredClone(previous.data);
      }
    }
    if (!replay) data = h[2](body, id);
  } catch (e) {
    st = e.code || 500;
    data = { error: e.message };
  }
  if (!replay && m === 'POST' && h?.[1] !== '/items' && key && st < 300)
    DB.idem.set(key, { signature, status: st, data: structuredClone(data) });
  if (st < 300) UI.used.add(ep);
  UI.log.unshift({
    ep,
    st,
    key,
    replay,
    path: path.replace(/[0-9a-f-]{36}/, (x) => sh(x)),
    t: new Date(now()).toLocaleTimeString('ru-RU'),
    err: st >= 300 ? data.error : '',
  });
  UI.log.length = Math.min(UI.log.length, 60);
  return { status: st, data, replay };
}
