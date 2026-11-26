const chip = (t, c = '') => `<span class="chip ${c}">${t}</span>`,
  SC = { CREATED: 'ac', ASSEMBLED: 'wn', ISSUED: 'ok', RETURNED: 'er' },
  SN = { CREATED: 'создан', ASSEMBLED: 'собран', ISSUED: 'выдан', RETURNED: 'возврат' };
const opt = (a, v, f) =>
  a
    .map(
      (x) =>
        `<option value="${esc(x.id)}"${x.id === v ? ' selected' : ''}>${esc(f(x))}</option>`,
    )
    .join('');
const route = (segs = []) =>
  `<div class="route">${segs.map((s) => `<div class="nd ${s.is_stub || s.h ? 'h' : ''} ${s.e ? 'e' : ''}">${esc(s.n)}<small>${s.is_stub ? 'Заглушка · ' : ''}${esc(s.l)}</small></div>`).join('')}</div>`;

function variant(v, win) {
  // pickable=false в деталях заказа (только подсветка hover), true — в форме выбора (клик выбирает схему)
  const pickable = arguments[2] !== false;
  const picked = pickable && UI.pick === v.scheme;
  const cls = `${v.ok && picked ? 'picked' : ''}`;
  return `<div class="v ${win ? 'win' : ''} ${cls} ${v.ok ? '' : 'no'}" ${pickable && v.ok ? `data-a="pick" data-scheme="${esc(v.scheme)}" title="Нажмите, чтобы выбрать ${esc(v.scheme)}" tabindex="0" role="button"` : ''}><div class="row" style="margin:0;justify-content:space-between"><b>${esc(v.scheme)}</b>${win ? chip('рекомендуется', 'ac') : picked ? chip('выбрана', 'ac') : v.ok ? '' : chip('недоступно', 'er')}</div>
 ${variantBody(v, arguments[3] ?? 'COST')}${route(v.segs)}</div>`;
}
// Ключевая метрика карточки по приоритету: COST — цена крупно наверху, TIME — срок крупно наверху, вторая метрика мелкой.
function variantBody(v, pref) {
  const cost = v.ok ? rub(v.cost) : '—';
  const hours = v.ok ? Number(v.hours).toFixed(1) + ' ч' : 'Недоступно';
  return pref === 'TIME'
    ? `<div class="big">${hours}</div><div class="mu">${cost} · ${esc(v.why)}</div>`
    : `<div class="big">${cost}</div><div class="mu">${hours} · ${esc(v.why)}</div>`;
}
function orderRow(o, pvzView) {
  const i = it(o.item);
  return `<tr><td title="${esc(o.id)}">${esc(sh(o.id))}</td><td>${esc(i?.name ?? o.item)} × ${esc(o.qty)}</td>${pvzView ? '' : `<td>${esc(pv(o.target)?.name ?? o.target)}</td>`}<td>${chip(Object.hasOwn(SN, o.status) ? SN[o.status] : esc(o.status), Object.hasOwn(SC, o.status) ? SC[o.status] : '')}</td><td>${chip(esc(o.chosen))}</td><td>${rub(o.cost)}</td><td>${Number(o.hours).toFixed(1)} ч ${Number(o.hours) <= Number(o.sla) ? '' : chip('вне SLA', 'er')}</td><td style="white-space:nowrap">${pvzView ? pvzAct(o) : `<button class="b" data-a="sel" data-id="${esc(o.id)}">Детали</button>`}</td></tr>`;
}
function pvzAct(o) {
  const b = (a, t, c = '') =>
    `<button class="b ${c}" data-a="${a}" data-id="${esc(o.id)}">${t}</button> `;
  return (
    (o.status === 'CREATED' && o.source_pvz_id === UI.pvz ? b('asm', 'Собрать', 'p') : '') +
    (o.status === 'ASSEMBLED'
      ? (!apiBase && UI.keys[o.id] && o.source_pvz_id === UI.pvz
          ? b('asm2', 'Повтор сборки')
          : '') + (o.target === UI.pvz ? b('iss', 'Выдать', 'p') : '')
      : '') +
    (o.status === 'ISSUED' && o.target === UI.pvz ? b('ret', 'Возврат') : '') +
    (['CREATED', 'ASSEMBLED'].includes(o.status) && !o.incidents?.length
      ? b('inc', 'Сообщить об ошибке', 'd')
      : '')
  );
}
const orderStages = [
  ['CREATED', 'Заказ создан'],
  ['ASSEMBLED', 'Заказ собран'],
  ['ISSUED', 'Заказ выдан'],
];
const eventLabels = {
  ...Object.fromEntries(orderStages),
  RETURNED: 'Возврат оформлен',
  ROUTE_CHANGED: 'Маршрут изменён',
  INCIDENT: 'Логистическая ошибка',
};

function orderTimeline(order) {
  const history = Array.isArray(order.history) ? order.history.filter(Boolean) : [];
  const events = history.length
    ? [...history]
    : [
        ...(order.created != null ? [{ type: 'CREATED', at: order.created }] : []),
        ...(order.incidents ?? []).map((incident) => ({
          type:
            incident.from && incident.to && incident.from !== incident.to
              ? 'ROUTE_CHANGED'
              : 'INCIDENT',
          at: incident.at,
          detail: [
            incident.from && incident.to ? incident.from + ' → ' + incident.to : '',
            incident.extra_cost != null ? '+' + rub(incident.extra_cost) : '',
            incident.extra_hours != null ? '+' + incident.extra_hours + ' ч' : '',
          ]
            .filter(Boolean)
            .join(' · '),
        })),
      ];
  const stages =
    order.status === 'RETURNED'
      ? [...orderStages, ['RETURNED', eventLabels.RETURNED]]
      : orderStages;
  const current = stages.findIndex(([type]) => type === order.status);
  const eventRow = (event, pending = false) => {
    const date = new Date(event.at ?? NaN);
    const knownTime = Number.isFinite(date.getTime());
    const extra = ['ROUTE_CHANGED', 'INCIDENT', 'RETURNED'].includes(event.type);
    return `<li class="order-event ${pending ? 'pending' : extra ? 'exception' : 'complete'}">
            <strong>${esc(Object.hasOwn(eventLabels, event.type) ? eventLabels[event.type] : (event.type ?? 'Событие заказа'))}</strong>
            ${pending ? '<span class="mu">Ожидается</span>' : knownTime ? `<time datetime="${date.toISOString()}">${fd(date)}</time>` : '<span class="mu">Время не указано</span>'}
            ${event.detail ? `<span class="mu">${esc(event.detail)}</span>` : ''}
          </li>`;
  };
  if (current >= 0 && !events.some((event) => event.type === order.status))
    events.push({ type: order.status });
  const pending = current < 0 ? [] : stages.slice(current + 1);
  return `<section class="order-history"><h4>История заказа</h4>
          ${events.length || pending.length ? `<ol>${events.map((event) => eventRow(event)).join('')}${pending.map(([type]) => eventRow({ type }, true)).join('')}</ol>` : '<p class="mu">События пока недоступны.</p>'}
        </section>`;
}

function orderListRows() {
  const pvzView = UI.role === 'P';
  const { query, status, point } = UI.orderFilters[UI.role];
  const text = query.trim().toLocaleLowerCase('ru-RU');
  const selectedPoint = pvzView ? UI.pvz : point;
  return DB.orders.filter((order) => {
    const target = order.target ?? order.target_pvz_id;
    return (
      (!selectedPoint || target === selectedPoint || order.source_pvz_id === selectedPoint) &&
      (!status || order.status === status) &&
      (!text ||
        String(order.id).toLowerCase().includes(text) ||
        String(it(order.item)?.name ?? order.item ?? '')
          .toLocaleLowerCase('ru-RU')
          .includes(text))
    );
  });
}

function orderListContent() {
  const pvzView = UI.role === 'P';
  const orders = orderListRows();
  if (!orders.length) return '<p class="mu" role="status">Заказы не найдены.</p>';
  return `<div class="tw"><table><thead><tr><th>ID</th><th>Товар</th>${pvzView ? '' : '<th>ПВЗ</th>'}<th>Статус</th><th>Схема</th><th>Стоимость</th><th>Срок</th><th>Действия</th></tr></thead>
          <tbody>${orders.map((order) => orderRow(order, pvzView)).join('')}</tbody></table></div>`;
}

function orderList() {
  const pvzView = UI.role === 'P';
  const filter = UI.orderFilters[UI.role];
  return `<section class="c order-list">
          <h3>${pvzView ? 'Заказы на этом ПВЗ' : 'Мои заказы'}</h3>
          <div class="row order-filters">
            <label>Поиск заказов<input type="search" data-order-filter="query" placeholder="Товар или ID" value="${esc(filter.query)}"></label>
            <label>Статус<select data-order-filter="status">
              <option value="">Все статусы</option>
              ${opt(
          [...orderStages, ['RETURNED', eventLabels.RETURNED]].map(([id, name]) => ({
            id,
            name,
          })),
          filter.status,
          (status) => status.name,
        )}
            </select></label>
            ${
        pvzView
          ? ''
          : `<label>Фильтр по ПВЗ<select data-order-filter="point">
              <option value="">Все ПВЗ</option>${opt(DB.pvz, filter.point, (point) => point.name)}
            </select></label>`
      }
            <button type="button" class="b" data-a="clearOrderFilters">Сбросить фильтры</button>
          </div>
          <div id="order-results">${orderListContent()}</div>
        </section>`;
}

function orderDetails(order, example = false) {
  return `<section class="c tracking-card" id="order-tracker">
          <div class="tracking-heading"><h3>${example ? 'Пример доставки' : `Заказ ${esc(sh(order.id))} · отслеживание`}</h3>${chip(esc(order.chosen), 'ac')}
            ${example ? '<button class="b" type="button" data-a="closeExample">Закрыть пример</button>' : ''}
          </div>
          ${deliveryTracker(order)}
          ${orderTimeline(order)}
          <p>${esc(order.reason ?? '')}</p>
          <p class="mu">Доставка ${rub(order.cost)} · базовый маршрут ${rub(order.base)} · SLA ${esc(order.sla)} ч
            ${example ? '' : `· ID ${esc(order.id)}`}
          </p>
          ${orderFinancials(order)}
          ${order.variants?.mesh && order.variants?.hub ? `<div class="g">${variant(order.variants.mesh, order.chosen === 'MESH', false)}${variant(order.variants.hub, order.chosen === 'HUB_TRUNK', false)}</div>` : ''}
        </section>`;
}

let exampleOrder = null;
function loadExampleOrder() {
  if (exampleOrder) return exampleOrder;
  const currentDB = DB;
  const currentUI = UI;
  try {
    UI = { ...currentUI, log: [], used: new Set() };
    seed();
    const result = createOrder(orderPayload());
    const source = pv(result.source_pvz_id);
    const target = pv(result.target);
    result.segs = result.segs.map((stop, index) => {
      const point = index === 0 ? source : index === result.segs.length - 1 ? target : null;
      return point
        ? { ...stop, lat: point.lat, lng: point.lng, address: point.address }
        : stop;
    });
    DB.off += 20 * 60 * 1000;
    call('POST', `/orders/${result.id}/assemble`, {}, uid());
    exampleOrder = read('GET', `/orders/${result.id}`);
    return exampleOrder;
  } finally {
    DB = currentDB;
    UI = currentUI;
  }
}

function orderFinancials(order) {
  const result = order.financials;
  const knownNumber = (value) =>
    value != null && value !== '' && Number.isFinite(Number(value));
  const fallback = [
    { label: 'Логистика', amount: order.cost },
    { label: 'Начисления ПВЗ', amount: result?.pvz_fees },
  ];
  const lines = (
    Array.isArray(result?.cost_lines) && result.cost_lines.length
      ? result.cost_lines
      : fallback
  ).filter((line) => knownNumber(line?.amount));
  const total =
    knownNumber(order.cost) && knownNumber(result?.pvz_fees)
      ? Number(order.cost) + Number(result.pvz_fees)
      : null;
  return `<details class="cost-breakdown">
          <summary>Из чего сложилась цена<span class="mu"> · Экономика заказа</span></summary>
          <dl class="order-financials">
            ${lines.map((line) => `<div><dt>${esc(line.label)}</dt><dd>${rub(line.amount)}</dd></div>`).join('')}
            ${total !== null ? `<div><dt>Всего расходов</dt><dd>${rub(total)}</dd></div>` : ''}
            ${knownNumber(result?.revenue) ? `<div><dt>Выручка</dt><dd>${rub(result.revenue)}</dd></div>` : ''}
            ${knownNumber(result?.result) ? `<div><dt>Результат по заказу</dt><dd>${rub(result.result)}</dd></div>` : ''}
            ${knownNumber(order.base) ? `<div><dt>Базовая логистика через хаб</dt><dd>${rub(order.base)}</dd></div>` : ''}
          </dl>
          ${!knownNumber(result?.pvz_fees) ? '<p class="mu">Начисления ПВЗ по заказу недоступны.</p>' : ''}
        </details>`;
}
