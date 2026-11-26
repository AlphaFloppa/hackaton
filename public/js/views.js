function orderPreview() {
  const q = UI.q;
  if (!q) return '<p class="mu">Нажмите «Сравнить схемы», чтобы увидеть доступные маршруты.</p>';
  const selected = UI.pick && [q.mesh, q.hub].find((v) => v.scheme === UI.pick);
  const unavailable = selected && !selected.ok;
  return `<div class="g">${variant(q.mesh, !UI.pick && q.win === q.mesh, true, UI.f.pref)}${variant(q.hub, !UI.pick && q.win === q.hub, true, UI.f.pref)}</div>
    ${unavailable ? `<p class="mu">Выбранная схема ${esc(selected.scheme)} недоступна. Выберите доступную схему.</p>` : ''}
    <p class="mu">Расчётный вес ${Number(q.ch).toFixed(2)} кг · хаб и магистраль — заглушки, ставки — допущение</p>`;
}

function vSeller() {
  const t = UI.tab.S,
    f = UI.f;
  let h = `<div class="tabs">${[
    ['order', 'Заказы'],
    ['items', 'Товары и остатки'],
    ['an', 'Аналитика'],
  ]
    .map(
      (x) =>
        `<button class="${t === x[0] ? 'on' : ''}" data-a="tab" data-v="${x[0]}">${x[1]}</button>`,
    )
    .join('')}</div>`;
  if (t === 'order') {
    h += `<div class="c"><h3>Новый заказ</h3><div class="row"><label>Товар<select data-f="item">${opt(DB.items, f.item, (i) => i.name)}</select></label><label>Кол-во<input data-f="qty" type="number" min="1" value="${esc(f.qty)}" style="width:80px"></label><label>Целевой ПВЗ<select data-f="tgt">${opt(DB.pvz, f.tgt, (p) => p.name)}</select></label><label>Приоритет<select data-f="pref"><option value="COST"${f.pref === 'COST' ? ' selected' : ''}>Стоимость</option><option value="TIME"${f.pref === 'TIME' ? ' selected' : ''}>Срок</option></select></label><button class="b" data-a="calc">Сравнить схемы</button><button class="b p" data-a="mk">Создать заказ${UI.pick ? ` · ${esc(UI.pick)}` : ''}</button></div>
  <div id="route-preview">${orderPreview()}</div>
 <div class="c" style="margin-top:12px"><h4>Объявить завоз</h4><p class="mu">Сначала завоз партии на ПВЗ — затем заказ: ПВЗ принимает товар только по завозу. Завоз сверх вместимости ПВЗ будет отклонён.</p><div class="row"><label>Товар<select data-f="item">${opt(DB.items, f.item, (i) => i.name)}</select></label><label>ПВЗ<select data-f="supplyPvz">${opt(DB.pvz, f.supplyPvz ?? f.tgt, (p) => p.name)}</select></label><label>Кол-во<input data-f="supplyQty" type="number" min="1" value="${esc(f.supplyQty ?? 5)}" style="width:80px"></label><button class="b" data-a="supply">Объявить завоз</button></div><div style="margin-top:10px">${supplyTable(read('GET', '/supplies'))}</div></div></div>
 ${orderList()}`;
    if (UI.exampleOpen) {
      h += orderDetails(loadExampleOrder(), true);
    } else if (UI.sel && DB.orders.some((order) => order.id === UI.sel)) {
      h += orderDetails(read('GET', '/orders/' + UI.sel));
    }
  }

  if (t === 'items') {
    h += `<div class="g"><div class="c"><h3>Новый товар</h3><div class="row"><label>Название<input id="in" data-draft="name" placeholder="Футболка" value="${esc(UI.itemDraft.name)}"></label><label>Вес, кг<input id="ik" data-draft="kg" type="number" step=".1" value="${esc(UI.itemDraft.kg)}" style="width:80px"></label><label>Объём, м³<input id="iv" data-draft="vol" type="number" step=".001" value="${esc(UI.itemDraft.vol)}" style="width:90px"></label><label>Цена, ₽<input id="ip" data-draft="price" type="number" value="${esc(UI.itemDraft.price)}" style="width:80px"></label><button class="b p" data-a="newitem">Создать</button></div><p class="mu">Укажи вес, объём и цену одной единицы товара.</p></div></div>
 <div class="c tw" style="margin-top:14px"><h3>Где лежит товар</h3><table><tr><th>Товар</th><th>ПВЗ</th><th>Остаток</th><th>Резерв</th><th>Возраст</th></tr>${DB.items
   .map((i) => {
     const batches = read('GET', `/items/${i.id}/batches`);
     if (!batches.length)
 return `<tr><td>${esc(i.name)}</td><td>Нет партий</td><td>0</td><td>0</td><td>—</td></tr>`;
     return batches
 .map(
   (b) =>
     `<tr><td>${esc(i.name)}</td><td>${esc(b.pvz)}</td><td>${esc(b.qty)}</td><td>${esc(b.reserved)}</td><td>${esc(b.age_days)} сут.</td></tr>`,
 )
 .join('');
   })
   .join('')}</table></div>
 <div class="c" style="margin-top:14px"><h3>Объявить завоз</h3><p class="mu">Завоз — заказ на доставку партии на ПВЗ. ПВЗ сможет принять товар только по существующему завозу. Учтите вместимость ПВЗ: завоз сверх свободного места будет отклонён (409).</p><div class="row"><label>Товар<select data-f="item">${opt(DB.items, f.item, (i) => i.name)}</select></label><label>ПВЗ<select data-f="supplyPvz">${opt(DB.pvz, f.supplyPvz ?? f.tgt, (p) => p.name)}</select></label><label>Кол-во<input data-f="supplyQty" type="number" min="1" value="${esc(f.supplyQty ?? 5)}" style="width:80px"></label><button class="b p" data-a="supply">Объявить завоз</button></div></div>
 <div class="c tw" style="margin-top:14px"><h3>Мои завозы</h3>${supplyTable(read('GET', '/supplies'))}</div>`;
  }
  if (t === 'an') {
    const a = read('GET', '/analytics/seller/' + DB.seller.id);
    const mx = Math.max(a.base_cost, a.mesh_cost, 1);
    const percent = a.base_cost > 0 ? ` · ${Number(a.saving_pct).toFixed(1)}%` : '';
    const savings = a.comparable_orders ? rub(a.saving) + percent : '—';
    const comparison = a.comparable_orders
      ? `<p class="mu">Через хаб</p><div class="bar"><i style="width:${(a.base_cost / mx) * 100}%"></i></div><b>${rub(a.base_cost)}</b>
         <p class="mu">Выбранные схемы</p><div class="bar"><i style="width:${(a.mesh_cost / mx) * 100}%"></i></div><b>${rub(a.mesh_cost)}</b>`
      : '<p class="mu">Пока нет заказов с расчётом обеих стоимостей.</p>';
    const revenueLabel = apiBase ? 'Выручка от доставки выданных заказов' : 'Выручка по выданным заказам';
    h += `<div class="g">
    <div class="c"><div class="mu">${apiBase ? 'Результат продавца по доставке' : 'Результат продавца'}</div><div class="big">${rub(a.profit)}</div>
      <div class="mu">${revenueLabel} ${rub(a.revenue)} − расчётная логистика ${rub(a.logistics)} − начисления ПВЗ ${rub(a.pvz_fees)}. Себестоимость товара и налоги не учитываются.</div>
    </div>
    <div class="c"><div class="mu">Разница с базой через хаб</div><div class="big">${savings}</div>
      <div class="mu">Для сравнения: ${esc(a.comparable_orders)} из ${esc(a.orders)} заказов</div>
    </div>
  </div>
  <div class="c" style="margin-top:14px"><h3>Стоимость трансфера: через хаб и по выбранным схемам</h3>${comparison}</div>`;
  }
  return h;
}
function vPvz() {
  const t = UI.tab.P,
    p = pv(UI.pvz),
    f = UI.f;
  let h = `<div class="row"><label>ПВЗ<select data-f="pvz">${opt(DB.pvz, UI.pvz, (x) => x.name)}</select></label><span class="mu">${esc(p.address)} · приёмка ${rub(p.fi)} · сборка ${rub(p.fa)} · выдача ${rub(p.fis)} · хранение ${rub(p.fs)}/ед./сут. ${chip('допущение, не тариф RWB', 'stub')}</span></div>
 <div class="tabs">${[
   ['orders', 'Заказы'],
   ['stock', 'Остатки и приёмка'],
   ['an', 'Аналитика'],
   ['nt', 'Уведомления'],
 ]
   .map(
     (x) =>
 `<button class="${t === x[0] ? 'on' : ''}" data-a="tab" data-v="${x[0]}">${x[1]}</button>`,
   )
   .join('')}</div>`;
  if (t === 'orders') h += orderList();
  if (t === 'stock') {
    const s = read('GET', `/pvz/${p.id}/stock`);
    h += `<div class="g"><div class="c"><h3>Занято и зарезервировано ${s.capacity > 0 && s.load / s.capacity >= 1 ? chip('заполнен — завоз/приёмка невозможны', 'er') : s.capacity > 0 && s.load / s.capacity >= 0.8 ? chip('загрузка ≥ 80% — крупный завоз не пройдёт', 'wn') : ''}</h3><div class="big">${esc(s.load)} / ${esc(s.capacity)}</div><div class="bar"><i style="width:${s.capacity > 0 ? Math.min(100, (s.load / s.capacity) * 100) : 0}%"></i></div></div>
 <div class="c"><h3>Входящие завозы</h3><p class="mu">Принять товар можно только по завозу продавца. Свободная приёмка отключена.</p>${suppliesPvz(read('GET', '/supplies', { pvz_id: p.id }))}</div></div>
 <div class="c tw" style="margin-top:14px"><table><tr><th>Товар</th><th>Остаток</th><th>Резерв</th><th>Возраст</th></tr>${s.rows.map((r) => `<tr><td>${esc(r.item)}</td><td>${esc(r.qty)}</td><td>${esc(r.reserved)}</td><td>${esc(r.age_days)} сут. ${r.age_days >= 7 ? chip('долгое хранение', 'wn') : ''}</td></tr>`).join('')}</table></div>`;
  }
  if (t === 'an') {
    const a = read('GET', '/analytics/pvz/' + p.id);
    const result = a.cost == null
      ? 'Нет данных о затратах по заказам.'
      : `Расчётные затраты по заказам ${rub(a.cost)} · расчётный результат ${rub(a.profit)}.`;
    h += `<div class="g"><div class="c"><div class="mu">Начислено ПВЗ с учётом возвратов</div><div class="big">${rub(a.income)}</div><div class="mu">${result}</div></div>
  <div class="c"><h3>Проводки ПВЗ</h3><table>${[
   ['Приёмка', a.intake],
   ['Сборка', a.assembly],
   ['Выдача', a.issue],
   ['Хранение', a.storage],
   ['Корректировки (возвраты)', a.corrections],
 ]
   .map((r) => `<tr><td>${r[0]}</td><td>${rub(r[1])}</td></tr>`)
   .join('')}</table></div></div>`;
  }
  if (t === 'nt') {
    const n = read('GET', '/notifications', { pvz_id: p.id });
    h += `<div class="c"><h3>Уведомления</h3>${n.length ? n.map((x) => `<div class="st"><span>${chip(x.type === 'ERROR' ? 'ошибка' : x.type === 'LONG_STORAGE' ? 'хранение' : 'инфо', x.type === 'ERROR' ? 'er' : x.type === 'LONG_STORAGE' ? 'wn' : '')}</span><span>${esc(x.text)}<br><span class="mu">${fd(x.t)}</span></span></div>`).join('') : '<p class="mu">Уведомлений нет. Они появятся при ошибке маршрута или хранении от 7 суток.</p>'}</div>`;
  }
  return h;
}
function vDemo() {
  let h = `<div class="c"><h2>Пять обязательных сценариев</h2><p class="mu">Прогон использует отдельный тестовый набор. Отчёт покажет статусы, остатки и суммы до и после операций.</p><button class="b p" data-a="run">Прогнать все сценарии</button></div>`;
  if (UI.scen)
    h += UI.scen
      .map(
        (s) =>
          `<div class="c" style="margin-top:14px"><h3>${s.t} ${chip(s.st.every((x) => x.ok) ? 'пройден' : 'ошибка', s.st.every((x) => x.ok) ? 'ok' : 'er')}</h3>${s.st.map((x) => `<div class="st"><span class="m ${x.ok ? 'ok-t' : 'er-t'}">${x.ok ? '✓' : '✗'}</span><span>${x.t}<br><span class="mu">${x.d || ''}</span></span></div>`).join('')}</div>`,
      )
      .join('');
  return h;
}
function render() {
  stopDeliveryMap?.();
  stopDeliveryMap = null;
  deliveryMap?.remove();
  deliveryMap = null;
  mapInput = null;
  $('#roles').innerHTML = [
    ['S', 'Продавец'],
    ['P', 'ПВЗ'],
    ...(!apiBase ? [['X', 'Сценарии']] : []),
  ]
    .map(
      (r) =>
        `<button class="${UI.role === r[0] ? 'on' : ''}" data-a="role" data-v="${r[0]}">${r[1]}</button>`,
    )
    .join('');
  const timeLabel = !apiBase || DB.now_override ? 'Тестовое время: ' : 'Текущее время: ';
  $('#clock').textContent = timeLabel + fd(now());
  $('#msg').innerHTML = UI.msg
    ? `<div class="msg ${UI.msg.ok ? 'ok' : 'er'}">${esc(UI.msg.t)}</div>`
    : '';
  const titles = {
    S: ['Кабинет продавца', 'Управляйте товарами, остатками и доставкой заказов.'],
    P: ['Кабинет ПВЗ', 'Принимайте партии, обрабатывайте заказы и проверяйте начисления.'],
    X: ['Демонстрация', 'Проверьте штатные и нештатные операции на тестовых данных.'],
  };
  const [title, description] = titles[UI.role];
  let content;
  try {
    if (screenError) throw Error(screenError);
    content = UI.role === 'S' ? vSeller() : UI.role === 'P' ? vPvz() : vDemo();
  } catch (error) {
    content = `<div class="c" role="alert">${esc(error.message)} <button class="b" data-a="refresh">Обновить</button></div>`;
  }
  $('#app').innerHTML =
    `<section class="workspace-heading"><div class="screen-tag">${chip(apiBase ? 'Серверный режим' : 'Локальное демо', 'ac')}</div><h1>${title}</h1><p class="mu">${description}</p>${UI.role === 'S' && DB?.seller ? `<p class="mu">Продавец: <b>${esc(DB.seller.name)}</b>${DB.seller.city ? ' · город: ' + esc(DB.seller.city) : ''}</p>` : ''}${!apiBase && UI.role === 'S' ? '<button class="b" style="margin-top:12px" type="button" data-a="openExample">Показать пример доставки</button>' : ''}</section>${content}`;
  mountDeliveryMap();
  $('#app').setAttribute('aria-busy', 'false');
}


function supplyTable(rows) {
  if (!rows || !rows.length) return '<p class="mu">Завозов пока нет. Объявите завоз, чтобы ПВЗ мог принять партию.</p>';
  const statusChip = (s) => chip(s === 'ACCEPTED' ? 'принят' : s === 'CANCELLED' ? 'отменён' : 'ожидает приёмки', s === 'ACCEPTED' ? 'ok' : s === 'CANCELLED' ? 'er' : 'wn');
  return `<table><tr><th>Товар</th><th>ПВЗ</th><th>Кол-во</th><th>Статус</th><th>Создан</th></tr>${rows.map((s) => `<tr><td>${esc(s.item)}</td><td>${esc(s.pvz)}</td><td>${esc(s.qty)}</td><td>${statusChip(s.status)}</td><td class="mu">${fd(s.created)}</td></tr>`).join('')}</table>`;
}

function suppliesPvz(rows) {
  if (!rows || !rows.length) return '<p class="mu">Входящих завозов нет. Они появятся, когда продавец объявит завоз на этот ПВЗ.</p>';
  const pending = rows.filter((s) => s.status === 'CREATED');
  return `${pending.length ? `<table><tr><th>Товар</th><th>Продавец</th><th>Кол-во</th><th></th></tr>${pending.map((s) => `<tr><td>${esc(s.item)}</td><td>${esc(s.seller)}</td><td>${esc(s.qty)}</td><td><button class="b p" data-a="accept" data-id="${esc(s.id)}">Принять</button></td></tr>`).join('')}</table>` : '<p class="mu">Новых завозов нет — все приняты.</p>'}
  <div style="margin-top:10px"><h4>История</h4>${supplyTable(rows.filter((s) => s.status !== 'CREATED'))}</div>`;
}
