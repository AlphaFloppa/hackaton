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
    seller: { id: uid(), name: 'ИП Орлов' },
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
    pref: 'COST',
    item: items[0].id,
    intakeItem: items[0].id,
    tgt: pvz[2].id,
  };
  UI.keys = {};
  UI.q = null;
  UI.sel = null;
  UI.exampleOpen = false;
  UI.playExample = false;
}
const demoHubPositions = {
  MSK: { lat: 55.73, lng: 37.67 },
  SPB: { lat: 59.894573, lng: 30.39197 },
};
function quote(i, qty, t) {
  const ch = Math.max(i.kg * qty, i.vol * qty * 250);
  const c = DB.batches
    .filter((b) => b.item === i.id && b.qty - b.res >= qty && pv(b.pvz).active)
    .map((b) => ({ b, d: km(pv(b.pvz), t) }))
    .sort((x, y) => x.d - y.d)[0];
  if (!c) throw E(409, 'Нет партии с остатком ' + qty + ' ед. ни на одном ПВЗ');
  const sp = pv(c.b.pvz),
    same = sp.city === t.city,
    d = Math.max(c.d, 0.5),
    full = !t.active || load(t.id) + (sp.id === t.id ? 0 : qty) > t.cap,
    blk = !t.active
      ? 'Целевой ПВЗ недоступен'
      : 'Целевой ПВЗ заполнен (' + load(t.id) + '/' + t.cap + ')';
  const hf = call('POST', '/stub/hub/handle', { chargeable_kg: ch }, uid()).data.hub_fee,
    tr = call(
      'POST',
      '/stub/trunk/move',
      { chargeable_kg: ch, km: same ? 12 : d },
      uid(),
    ).data;
  const mc = 20 + ch * d * 0.35,
    mh = 3 + d / 40;
  const mesh = {
    scheme: 'MESH',
    cost: mc,
    hours: mh,
    ok: !full && same,
    why: full
      ? blk
      : !same
        ? 'Прямой рейс доступен только внутри города'
        : 'прямая доставка между ПВЗ',
    segs: [
      { n: sp.name, l: 'партия' },
      { n: t.name, l: rub(mc) + ' · ' + mh.toFixed(1) + ' ч', e: 1 },
    ],
  };
  const hc = 18 + hf.total + tr.cost + 25,
    hh = 4 + 6 + tr.hours + 3;
  const hub = {
    scheme: 'HUB_TRUNK',
    cost: hc,
    hours: hh,
    ok: !full,
    why: full ? blk : 'через хаб и магистраль',
    segs: [
      { n: sp.name, l: 'партия' },
      {
        n: 'Хаб',
        l: rub(hf.total),
        is_stub: true,
        ...demoHubPositions[DB.cities.find((city) => city.id === sp.city)?.code],
      },
      {
        n: 'Магистраль',
        l: rub(tr.cost) + ' · ' + tr.hours.toFixed(1) + ' ч',
        is_stub: true,
      },
      { n: t.name, l: 'последняя миля ' + rub(25), e: 1 },
    ],
  };
  return {
    mesh,
    hub,
    src: c.b,
    srcName: sp.name,
    ch,
    same,
    block:
      blk +
      (full
        ? '. Свободные ПВЗ города: ' +
          (DB.pvz
            .filter(
              (p) =>
                p.active &&
                p.city === t.city &&
                p.id !== t.id &&
                load(p.id) + (p.id === sp.id ? 0 : qty) <= p.cap,
            )
            .map((p) => p.name)
            .join(', ') || 'нет')
        : ''),
  };
}
function createOrder(b) {
  const i = it(b.item_id),
    t = pv(b.target_pvz_id),
    qty = +b.qty;
  if (!i || !t || !Number.isSafeInteger(qty) || qty < 1)
    throw E(422, 'Проверьте товар, ПВЗ и количество');
  const q = quote(i, qty, t),
    pref = b.preference === 'TIME' ? 'TIME' : 'COST',
    ok = [q.mesh, q.hub].filter((v) => v.ok);
  if (!ok.length) throw E(409, q.block);
  const ch = [...ok].sort((a, c) =>
      pref === 'COST' ? a.cost - c.cost : a.hours - c.hours,
    )[0],
    alt = ok.find((v) => v !== ch),
    oth = ch === q.mesh ? q.hub : q.mesh;
  const reason = alt
    ? `Выбрана ${ch.scheme} при приоритете «${pref === 'COST' ? 'стоимость' : 'срок'}»: ${pref === 'COST' ? 'дешевле на ' + rub(alt.cost - ch.cost) + ' (' + Math.round((1 - ch.cost / alt.cost) * 100) + '%)' : 'быстрее на ' + (alt.hours - ch.hours).toFixed(1) + ' ч'}. Альтернатива ${alt.scheme}: ${rub(alt.cost)}, ${alt.hours.toFixed(1)} ч.`
    : `Единственная допустимая схема — ${ch.scheme}. ${oth.scheme}: ${oth.why}.`;
  q.src.res += qty;
  const sla = q.same ? 24 : 72;
  const o = {
    id: uid(),
    seller: DB.seller.id,
    item: i.id,
    qty,
    target: t.id,
    source_pvz_id: q.src.pvz,
    pref,
    status: 'CREATED',
    chosen: ch.scheme,
    variants: { mesh: q.mesh, hub: q.hub },
    segs: ch.segs,
    cost: ch.cost,
    cost0: ch.cost,
    base: q.hub.cost,
    hours: ch.hours,
    sla,
    reason,
    batch: q.src.id,
    price: i.price * qty,
    created: now(),
    history: [{ type: 'CREATED', at: now() }],
    incidents: [],
  };
  DB.orders.unshift(o);
  return o;
}
