const apiBase = new URLSearchParams(location.search).get('api')?.trim().replace(/\/+$/, '');
let busy = false;
let screenError = '';
let screenData = new Map();
const pendingKeys = new Map();

const cacheKey = (path, query = {}) => path + '?' + new URLSearchParams(query);
function read(method, path, query = {}) {
  if (!apiBase) return call(method, path, query).data;
  const key = cacheKey(path, query);
  if (!screenData.has(key)) throw Error('Данные экрана недоступны. Обновите страницу.');
  return screenData.get(key);
}

async function request(method, path, payload = {}) {
  // GET-запросы UI идут на /frontend/*-адаптер (форма данных под вьюхи)
  const apiPath = method === 'GET' && path !== '/health'
    ? path.replace(/^\/items\/([^/]+)\/batches$/, '/frontend/items/$1/batches')
          .replace(/^\/orders\/([^/]+)$/, '/frontend/orders/$1')
          .replace(/^\/pvz\/([^/]+)\/stock$/, '/frontend/pvz/$1/stock')
          .replace(/^\/analytics\/seller\//, '/frontend/analytics/seller/')
          .replace(/^\/analytics\/pvz\//, '/frontend/analytics/pvz/')
          .replace(/^\/notifications$/, '/frontend/notifications')
    : path;
  const url = new URL(apiBase + apiPath, location.href);
  if (!['http:', 'https:'].includes(url.protocol)) throw Error('Укажите HTTP-адрес API.');
  const headers = { Accept: 'application/json' };
  const body = method === 'POST' ? JSON.stringify(payload) : undefined;
  const signature = path + ' ' + body;
  if (body) {
    headers['Content-Type'] = 'application/json';
    if (path !== '/items') {
      if (!pendingKeys.has(signature)) pendingKeys.set(signature, uid());
      headers['Idempotency-Key'] = pendingKeys.get(signature);
    }
  } else {
    url.search = new URLSearchParams(payload).toString();
  }
  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body,
      signal: AbortSignal.timeout(10000),
    });
  } catch {
    throw Error(
      'Сервер недоступен. Проверьте адрес API и настройки CORS. Повтор операции сохранит её ключ.',
    );
  }
  if (
    response.status >= 400 &&
    response.status < 500 &&
    ![408, 425, 429].includes(response.status)
  )
    pendingKeys.delete(signature);
  UI.log.unshift({
    t: new Date().toLocaleTimeString('ru-RU'),
    ep: method,
    path,
    st: response.status,
    key: headers['Idempotency-Key'],
  });
  UI.log.length = Math.min(UI.log.length, 60);
  if (response.ok) UI.used.add(method + ' ' + path.replace(/[0-9a-f-]{36}/g, '{id}'));
  if (response.status === 204 && method === 'POST') {
    pendingKeys.delete(signature);
    return null;
  }
  const result = await response.json().catch(() => null);
  if (!response.ok) {
    const reason = result?.error ?? result?.detail ?? result?.message;
    throw Error(
      `${method} ${path} · HTTP ${response.status}${typeof reason === 'string' ? ' · ' + reason : ''}`,
    );
  }
  if (result === null) throw Error(`Сервер должен вернуть JSON для ${method} ${path}.`);
  pendingKeys.delete(signature);
  return result;
}

async function refresh(bootstrap = false) {
  setBusy(true);
  if (!DB) $('#app').textContent = 'Подключение к API…';
  try {
    if (bootstrap || !DB) {
      const data = await request('GET', '/frontend/bootstrap');
      if (
        !data?.seller?.id ||
        !Array.isArray(data.pvz) ||
        !data.pvz.length ||
        !Array.isArray(data.items) ||
        !Array.isArray(data.orders)
      ) {
        throw Error(
          'Ответ /frontend/bootstrap не содержит справочники, заказы или продавца.',
        );
      }
      const serverTime = data.now ? Date.parse(data.now) : Date.now();
      DB = {
        ...data,
        off: 0,
        serverTime: Number.isFinite(serverTime) ? serverTime : Date.now(),
      };
      if (!pv(UI.pvz)) UI.pvz = DB.pvz[0].id;
      if (!pv(UI.f.tgt)) UI.f.tgt = DB.pvz[0].id;
      if (!it(UI.f.item)) UI.f.item = DB.items[0]?.id ?? '';
      if (!it(UI.f.intakeItem)) UI.f.intakeItem = DB.items[0]?.id ?? '';
      if (!DB.orders.some((order) => order.id === UI.sel)) UI.sel = null;
    }
    const requests = [];
    if (UI.role === 'S') {
      if (UI.tab.S === 'items')
        requests.push(...DB.items.map((item) => [`/items/${item.id}/batches`]));
      if (UI.tab.S === 'an') requests.push([`/analytics/seller/${DB.seller.id}`]);
      if (UI.tab.S === 'order' && UI.sel) requests.push([`/orders/${UI.sel}`]);
    } else {
      const paths = {
        stock: [`/pvz/${UI.pvz}/stock`],
        an: [`/analytics/pvz/${UI.pvz}`],
        nt: ['/notifications', { pvz_id: UI.pvz }],
      };
      if (paths[UI.tab.P]) requests.push(paths[UI.tab.P]);
    }
    const entries = await Promise.all(
      requests.map(async ([path, query]) => [
        cacheKey(path, query),
        await request('GET', path, query),
      ]),
    );
    screenData = new Map(entries);
    screenError = '';
  } catch (error) {
    screenError = error.message;
  } finally {
    setBusy(false);
    if (DB) render();
    else {
      $('#app').innerHTML =
        `<div class="c" role="alert">${esc(screenError)} <button class="b" data-a="refresh">Повторить подключение</button></div>`;
      $('#app').setAttribute('aria-busy', 'false');
    }
  }
}
