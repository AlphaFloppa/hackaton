const uid = () => {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return hex.replace(/^(........)(....)(....)(....)(............)$/, '$1-$2-$3-$4-$5');
  },
  sh = (i) => (i ? String(i).slice(0, 8) : '—');
const rub = (n) =>
  (Math.round(n * 100) / 100).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }) + ' ₽';
const esc = (s) =>
  String(s).replace(
    /[&<>"]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c],
  );
const $ = (s) => document.querySelector(s),
  T0 = Date.parse('2026-11-25T10:00:00Z'),
  DAY = 864e5;
let DB,
  UI = {
    role: 'S',
    pvz: null,
    tab: { S: 'order', P: 'orders', X: 'run' },
    log: [],
    used: new Set(),
    f: { qty: 2, intakeQty: 2, pref: 'COST' },
    orderFilters: {
      S: { query: '', status: '', point: '' },
      P: { query: '', status: '', point: '' },
    },
    exampleOpen: false,
    playExample: false,
    itemDraft: { name: '', kg: '0.3', vol: '0.003', price: '900' },
    q: null,
    pick: null,
    sel: null,
    keys: {},
    scen: null,
    msg: null,
  };
function setBusy(value) {
  busy = value;
  $('#app').setAttribute('aria-busy', String(value));
  document.querySelectorAll('button, input, select').forEach((control) => {
    if (value && !control.disabled) {
      control.disabled = true;
      control.dataset.pending = '';
    } else if (!value && 'pending' in control.dataset) {
      control.disabled = false;
      delete control.dataset.pending;
    }
  });
}
const now = () => DB.serverTime ?? T0 + DB.off,
  E = (c, m) => Object.assign(new Error(m), { code: c });
const pv = (id) => DB.pvz.find((p) => p.id === id),
  it = (id) => DB.items.find((i) => i.id === id);
const od = (id) => {
  const o = DB.orders.find((o) => o.id === id);
  if (!o) throw E(404, 'Заказ не найден');
  return o;
};
const load = (id) =>
  DB.batches.filter((b) => b.pvz === id).reduce((s, b) => s + b.qty, 0) +
  DB.orders
    .filter(
      (o) =>
        o.target === id &&
        ['CREATED', 'ASSEMBLED'].includes(o.status),
    )
    .reduce((s, o) => s + o.qty, 0);
const led = (kind, pvz, amt, why, oid) =>
  DB.ledger.push({ t: now(), kind, pvz, amt, why, oid });
const note = (type, pvz, text) => DB.notes.unshift({ t: now(), type, pvz, text });
const km = (a, b) =>
  Math.hypot(
    (a.lat - b.lat) * 111,
    (a.lng - b.lng) * 111 * Math.cos((a.lat * Math.PI) / 180),
  );
const sum = (a, f) => a.reduce((s, x) => s + f(x), 0),
  fd = (t) => new Date(t).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
