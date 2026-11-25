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
    const q = UI.q;
    h += `<div class="c"><h3>Новый заказ</h3><div class="row"><label>Товар<select data-f="item">${opt(DB.items, f.item, (i) => i.name)}</select></label><label>Кол-во<input data-f="qty" type="number" min="1" value="${esc(f.qty)}" style="width:80px"></label><label>Целевой ПВЗ<select data-f="tgt">${opt(DB.pvz, f.tgt, (p) => p.name)}</select></label><label>Приоритет<select data-f="pref"><option value="COST"${f.pref === 'COST' ? ' selected' : ''}>Стоимость</option><option value="TIME"${f.pref === 'TIME' ? ' selected' : ''}>Срок</option></select></label><button class="b" data-a="calc">Сравнить схемы</button><button class="b p" data-a="mk">Создать заказ</button></div>
 <div id="route-preview">${q ? `<div class="g">${variant(q.mesh, q.win === q.mesh)}${variant(q.hub, q.win === q.hub)}</div><p class="mu">Источник: ${esc(q.srcName)} · расчётный вес ${Number(q.ch).toFixed(2)} кг · хаб и магистраль — заглушки, ставки — допущение</p>` : '<p class="mu">Нажмите «Сравнить схемы», чтобы увидеть оба маршрута и выбор по приоритету.</p>'}</div></div>
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
   .join('')}</table></div>`;
  }
  if (t === 'an') {
    const a = read('GET', '/analytics/seller/' + DB.seller.id),
      mx = Math.max(a.base_cost, a.mesh_cost, 1);
    h += `<div class="g"><div class="c"><div class="mu">Операционный результат продавца</div><div class="big">${rub(a.profit)}</div><div class="mu">выручка ${rub(a.revenue)} − логистика ${rub(a.logistics)} − ПВЗ ${rub(a.pvz_fees)}. Себестоимость товара и налоги в демо не учитываются.</div></div>
  <div class="c"><div class="mu">Разница с базой через хаб</div><div class="big">${rub(a.saving)} · ${Number(a.saving_pct).toFixed(1)}%</div><div class="mu">${esc(a.orders)} заказов</div></div></div>
  <div class="c" style="margin-top:14px"><h3>База (всё через хаб) и выбранные схемы</h3><p class="mu">База</p><div class="bar"><i style="width:${(a.base_cost / mx) * 100}%"></i></div><b>${rub(a.base_cost)}</b><p class="mu">Выбранные схемы</p><div class="bar"><i style="width:${(a.mesh_cost / mx) * 100}%"></i></div><b>${rub(a.mesh_cost)}</b></div>`;
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
    h += `<div class="g"><div class="c"><h3>Занято и зарезервировано</h3><div class="big">${esc(s.load)} / ${esc(s.capacity)}</div><div class="bar"><i style="width:${s.capacity > 0 ? Math.min(100, (s.load / s.capacity) * 100) : 0}%"></i></div></div>
 <div class="c"><h3>Приёмка партии</h3><div class="row"><label>Товар<select data-f="intakeItem">${opt(DB.items, f.intakeItem, (i) => i.name)}</select></label><label>Кол-во<input data-f="intakeQty" type="number" min="1" value="${esc(f.intakeQty)}" style="width:80px"></label><button class="b p" data-a="intake">Принять</button></div></div></div>
 <div class="c tw" style="margin-top:14px"><table><tr><th>Товар</th><th>Остаток</th><th>Резерв</th><th>Возраст</th></tr>${s.rows.map((r) => `<tr><td>${esc(r.item)}</td><td>${esc(r.qty)}</td><td>${esc(r.reserved)}</td><td>${esc(r.age_days)} сут. ${r.age_days >= 7 ? chip('долгое хранение', 'wn') : ''}</td></tr>`).join('')}</table></div>`;
  }
  if (t === 'an') {
    const a = read('GET', '/analytics/pvz/' + p.id);
    h += `<div class="g"><div class="c"><div class="mu">Прибыль ПВЗ</div><div class="big">${rub(a.profit)}</div><div class="mu">доход ${rub(a.income)} · расходы ${rub(a.cost)} ${apiBase ? '' : chip('40% — допущение', 'stub')}</div></div>
 <div class="c"><h3>Доход по видам</h3><table>${[
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
    const n = read('GET', '/notifications', { pvz: p.id });
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
  $('#clock').textContent =
    'Время системы: ' + fd(now()) + (DB.off ? ` (+${Math.round(DB.off / DAY)} дн.)` : '');
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
    `<section class="workspace-heading"><div class="screen-tag">${chip(apiBase ? 'Серверный режим' : 'Локальное демо', 'ac')}</div><h1>${title}</h1><p class="mu">${description}</p>${!apiBase && UI.role === 'S' ? '<button class="b" style="margin-top:12px" type="button" data-a="openExample">Показать пример доставки</button>' : ''}</section>${content}`;
  mountDeliveryMap();
  $('#app').setAttribute('aria-busy', 'false');
  $('#csum').textContent =
    `Консоль API · покрытие ${EPS.filter((e) => UI.used.has(e)).length} из ${EPS.length} эндпоинтов`;
  $('#cov').innerHTML = EPS.map(
    (e) => `<span class="${UI.used.has(e) ? 'on' : ''}">${e}</span>`,
  ).join('');
  $('#lg').innerHTML =
    UI.log
      .map(
        (l) =>
          `${esc(l.t)} ${esc(l.ep)} ${esc(l.path)} <b class="${l.st < 300 ? 'k' : 'e'}">${l.st}</b>${l.key ? ' key=' + sh(l.key) : ''}${l.replay ? ' (повтор: ответ из кэша)' : ''}${l.err ? ' · ' + esc(l.err) : ''}`,
      )
      .join('<br>') || 'Запросов пока нет';
}
