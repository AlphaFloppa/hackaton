function seed() {
  const C = {
    m: { id: uid(), name: 'Москва', code: 'MSK' },
    s: { id: uid(), name: 'Санкт-Петербург', code: 'SPB' },
  };
  const mk = (c, n, a, lat, lng, cap, fi, fa, fis, fs) => ({
    id: uid(),
    city: C[c].id,
    name: n,
    address: a,
    lat,
    lng,
    cap,
    fi,
    fa,
    fis,
    fs,
    active: true,
  });
  const pvz = [
    mk('m', 'ПВЗ Москва-1', 'Тверская, 7', 55.76, 37.61, 100, 4, 18, 12, 1.5),
    mk('m', 'ПВЗ Москва-2', 'Арбат, 20', 55.75, 37.59, 60, 5, 20, 14, 1.8),
    mk('m', 'ПВЗ Москва-3', 'Ленинский пр., 90', 55.69, 37.53, 30, 4, 16, 10, 1.2),
    mk('s', 'ПВЗ СПб-1', 'Невский пр., 50', 59.93, 30.34, 80, 4, 17, 11, 1.4),
  ];
  const items = [
    { id: uid(), name: 'Кружка', kg: 0.4, vol: 0.002, price: 450 },
    { id: uid(), name: 'Плед', kg: 1.2, vol: 0.012, price: 1900 },
    { id: uid(), name: 'Куртка', kg: 1.5, vol: 0.03, price: 4200 },
  ];
  const bt = (i, p, q, d) => ({
    id: uid(),
    item: items[i].id,
    pvz: pvz[p].id,
    qty: q,
    res: 0,
    at: T0 - d * DAY,
    chg: T0 - d * DAY,
  });
  DB = {
    off: 0,
    cities: Object.values(C),
    pvz: pvz,
    hubs: [
      { id: uid(), city: C.m.id, name: 'Хаб Москва', is_stub: true },
      { id: uid(), city: C.s.id, name: 'Хаб СПб', is_stub: true },
    ],
    seller: { id: uid(), name: 'ИП Орлов', city_id: C.m.id },
    items,
    batches: [
      bt(0, 0, 40, 1),
      bt(0, 1, 15, 1),
      bt(0, 3, 20, 1),
      bt(1, 0, 10, 1),
      bt(1, 3, 5, 1),
      bt(1, 2, 26, 1),
      bt(2, 0, 30, 1),
    ],
    orders: [],
    ledger: [],
    notes: [],
    idem: new Map(),
    incidents: [],
  };
  UI.pvz = pvz[0].id;
  UI.f = {
    qty: 2,
    intakeQty: 2,
    supplyQty: 5,
    supplyPvz: pvz[0].id,
    pref: 'COST',
    item: items[0].id,
    intakeItem: items[0].id,
    tgt: pvz[2].id,
  };
  UI.keys = {};
  UI.q = null;
  UI.pick = null;
  UI.sel = null;
  UI.exampleOpen = false;
  UI.playExample = false;
}
const demoHubPositions = {
  MSK: { lat: 55.73, lng: 37.67 },
  SPB: { lat: 59.894573, lng: 30.39197 },
};
function quote(i, qty, t, ownIncoming = 0) {
  const ch = Math.max(i.kg * qty, i.vol * qty * 250);
  const batchesByPoint = new Map();
  for (const batch of DB.batches) {
    if (batch.item !== i.id || batch.qty <= batch.res) continue;
    if (!batchesByPoint.has(batch.pvz)) batchesByPoint.set(batch.pvz, []);
    batchesByPoint.get(batch.pvz).push(batch);
  }
  const local = DB.pvz
    .filter((point) => point.active && point.id !== t.id && point.city === t.city && batchesByPoint.has(point.id))
    .map((point) => ({ point, batches: batchesByPoint.get(point.id), d: km(point, t) }))
    .filter(({ batches }) => sum(batches, (batch) => batch.qty - batch.res) >= qty)
    .sort((x, y) => x.d - y.d)[0];
  const used = load(t.id) - ownIncoming;
  const targetBlock = !t.active
    ? 'Целевой ПВЗ недоступен'
    : used + qty > t.cap ? `В целевом ПВЗ недостаточно места (${used}/${t.cap})` : '';
  const noRoute = (scheme, why) => ({ scheme, cost: null, hours: null, ok: false, why, segs: [] });
  let mesh = noRoute('MESH', targetBlock || `В городе выдачи нет другого активного ПВЗ с доступным остатком от ${qty} ед.`);
  if (local && !targetBlock) {
    const d = Math.max(local.d, 0.5);
    const cost = 20 + ch * d * 0.35;
    const hours = 3 + d / 40;
    mesh = {
      scheme: 'MESH', cost, hours, ok: true, why: 'прямая доставка между ПВЗ',
      segs: [
        { n: local.point.name, l: 'партия' },
        { n: t.name, l: rub(cost) + ' · ' + hours.toFixed(1) + ' ч', e: 1 },
      ],
    };
  }

  const receivingPoints = DB.pvz.filter((p) => p.active && p.city === DB.seller.city_id
    && (p.id === t.id ? used : load(p.id)) + qty <= p.cap);
  const handoff = receivingPoints.find((p) => p.id !== t.id) ?? receivingPoints[0];
  const hub = !targetBlock && handoff
    ? demoHubRoute(handoff, t, ch)
    : noRoute('HUB_TRUNK', targetBlock || 'В городе продавца нет ПВЗ, готового принять завоз.');
  return { mesh, hub, local, handoff, ch, block: targetBlock || `${mesh.why} ${hub.why}` };
}

function demoHubRoute(handoff, target, ch) {
  const same = handoff.city === target.city;
  const hf = call('POST', '/stub/hub/handle', { chargeable_kg: ch }, uid()).data.hub_fee,
    tr = call(
      'POST',
      '/stub/trunk/move',
      { chargeable_kg: ch, km: same ? 12 : km(handoff, target) },
      uid(),
    ).data;
  return {
    scheme: 'HUB_TRUNK',
    cost: 18 + hf.total + tr.cost + 25,
    hours: 4 + 6 + tr.hours + 3,
    ok: true,
    why: 'через хаб и магистраль',
    segs: [
      { n: `Продавец · ${DB.seller.name}`, l: 'Точка отправления', kind: 'SELLER' },
      { n: handoff.name, l: 'Продавец привозит товар в ПВЗ' },
      {
        n: 'Хаб',
        l: rub(hf.total),
        is_stub: true,
        ...demoHubPositions[DB.cities.find((city) => city.id === handoff.city)?.code],
      },
      {
        n: 'Магистраль',
        l: rub(tr.cost) + ' · ' + tr.hours.toFixed(1) + ' ч',
        is_stub: true,
      },
      { n: target.name, l: 'последняя миля ' + rub(25), e: 1 },
    ],
  };
}

function reserveDemoSource(q, scheme, qty) {
  if (scheme === 'HUB_TRUNK') return { source_pvz_id: q.handoff.id, allocations: [] };
  const allocations = [];
  let remaining = qty;
  for (const batch of q.local.batches) {
    const take = Math.min(remaining, batch.qty - batch.res);
    allocations.push({ id: batch.id, qty: take });
    batch.res += take;
    remaining -= take;
    if (remaining === 0) break;
  }
  return { source_pvz_id: q.local.point.id, allocations };
}

function recommendedRoute(q, preference) {
  if (!q.mesh.ok) return q.hub.ok ? q.hub : null;
  if (!q.hub.ok) return q.mesh;
  const metric = preference === 'TIME' ? 'hours' : 'cost';
  return q.mesh[metric] <= q.hub[metric] ? q.mesh : q.hub;
}
function createOrder(b) {
  const i = it(b.item_id),
    t = pv(b.target_pvz_id),
    qty = +b.qty;
  if (!i || !t || !Number.isSafeInteger(qty) || qty < 1)
    throw E(422, 'Проверьте товар, ПВЗ и количество');
  const q = quote(i, qty, t);
  const pref = b.preference === 'TIME' ? 'TIME' : 'COST';
  const requested = b.scheme == null ? null : [q.mesh, q.hub].find((v) => v.scheme === b.scheme);
  if (requested === undefined) throw E(422, 'Схема должна быть MESH или HUB_TRUNK');
  if (requested && !requested.ok) throw E(409, `Схема ${requested.scheme} недоступна. ${requested.why}`);
  const chosen = requested ?? recommendedRoute(q, pref);
  if (!chosen) throw E(409, q.block);
  const other = chosen === q.mesh ? q.hub : q.mesh;
  const reason = requested
    ? `Схема ${chosen.scheme} выбрана продавцом.`
    : other.ok
      ? `Выбрана ${chosen.scheme} при приоритете «${pref === 'COST' ? 'стоимость' : 'срок'}». Альтернатива ${other.scheme}: ${rub(other.cost)}, ${other.hours.toFixed(1)} ч.`
      : `Единственная допустимая схема — ${chosen.scheme}. ${other.why}`;
  const source = reserveDemoSource(q, chosen.scheme, qty);
  const sla = pv(source.source_pvz_id).city === t.city ? 24 : 72;
  const o = {
    id: uid(),
    seller: DB.seller.id,
    item: i.id,
    qty,
    target: t.id,
    ...source,
    pref,
    status: 'CREATED',
    chosen: chosen.scheme,
    variants: { mesh: q.mesh, hub: q.hub },
    segs: chosen.segs,
    cost: chosen.cost,
    cost0: chosen.cost,
    base: q.hub.cost,
    hours: chosen.hours,
    sla,
    reason,
    price: i.price * qty,
    created: now(),
    history: [{ type: 'CREATED', at: now() }],
    incidents: [],
  };
  DB.orders.unshift(o);
  return o;
}
