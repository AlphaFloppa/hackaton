function orderPayload() {
  const { item, qty, tgt, pref } = UI.f;
  if (!it(item) || !pv(tgt) || !Number.isSafeInteger(+qty) || +qty < 1) {
    throw Error('Выберите товар и ПВЗ, укажите целое количество от 1.');
  }
  return { item_id: item, qty: +qty, target_pvz_id: tgt, preference: pref };
}

function itemPayload() {
  const { name, kg, vol, price } = UI.itemDraft;
  if (!name.trim()) throw E(422, 'Укажите название товара');
  if (
    ![kg, vol, price].every((value) => value.trim() && Number.isFinite(Number(value))) ||
    Number(kg) <= 0 ||
    Number(vol) <= 0 ||
    Number(price) < 0
  ) {
    throw E(422, 'Вес и объём должны быть больше нуля, цена — от нуля');
  }
  return { name: name.trim(), kg: Number(kg), vol: Number(vol), price: Number(price) };
}

function intakePayload() {
  const { intakeItem, intakeQty } = UI.f;
  const qty = Number(intakeQty);
  if (!it(intakeItem) || !Number.isSafeInteger(qty) || qty < 1)
    throw E(422, 'Выберите товар и укажите целое количество от 1');
  return { item_id: intakeItem, qty };
}

function supplyPayload() {
  const { item, supplyQty, supplyPvz } = UI.f;
  const qty = Number(supplyQty);
  if (!it(item) || !pv(supplyPvz) || !Number.isSafeInteger(qty) || qty < 1)
    throw E(422, 'Выберите товар, ПВЗ и укажите целое количество от 1');
  return { item_id: item, pvz_id: supplyPvz, qty };
}

async function remoteAction(action, data) {
  if (['role', 'tab', 'sel'].includes(action)) {
    A[action](data);
    await refresh();
    return;
  }
  if (action === 'refresh') {
    await refresh(true);
    return;
  }
  if (!DB && action !== 'health') return;
  const id = data.id;
  const operations = {
    mk: () => ['/orders', orderPayload(), 'Заказ создан'],
    newitem: () => ['/items', itemPayload(), 'Товар создан'],
    supply: () => ['/supplies', supplyPayload(), 'Завоз объявлен'],
    accept: (d) => [`/supplies/${d.id}/accept`, {}, 'Завоз принят'],
    asm: () => [`/orders/${id}/assemble`, {}, 'Заказ собран'],
    iss: () => [`/orders/${id}/issue`, {}, 'Заказ выдан'],
    ret: () => [`/orders/${id}/return`, {}, 'Возврат оформлен'],
    inc: () => ['/incidents', { order_id: id, type: 'WRONG_PVZ' }, 'Ошибка зафиксирована'],
    adv: () => ['/system/time/advance', { hours: (+data.d || 0) * 24 }, 'Время сдвинуто'],
  };
  setBusy(true);
  try {
    if (action === 'calc') {
      UI.q = null;
      const result = await request('GET', '/frontend/quote', orderPayload());
      UI.q = {
        ...result,
        win: [result.mesh, result.hub].find((v) => v?.ok && v.scheme === result.chosen),
      };
      M(result.reason || result.block || 'Маршруты рассчитаны', Boolean(UI.q.win));
    } else if (action === 'health') {
      await request('GET', '/health');
      M('Сервер ответил на проверку состояния', true);
    } else {
      const [path, payload, message] = operations[action](data);
      const result = await request('POST', path, payload);
      if (action === 'mk') UI.sel = result?.id ?? result?.order_id ?? result?.data?.id ?? null;
      if (action === 'newitem') UI.itemDraft.name = '';
      UI.q = null;
      M(message, true);
      await refresh(true);
      return;
    }
  } catch (error) {
    M(error.message, false);
  } finally {
    setBusy(false);
    render();
  }
}

const M = (t, ok) => {
  UI.msg = { t, ok };
};
const res = (r, okT) =>
  M(
    r.status < 300
      ? okT +
          (r.replay
            ? ' · повторный ключ, ответ из кэша'
            : r.data?.replay
              ? ' · ' + r.data.note
              : '')
      : 'Ошибка ' + r.status + ': ' + r.data.error,
    r.status < 300,
  );
const A = {
  pick: (d) => {
    // Выбор схемы доставки: подсветка карточки + подгонка приоритета под выбранную схему,
    // чтобы серверный Router действительно её выбрал.
    UI.pick = d.scheme;
    const q = UI.q;
    if (q) {
      const v = d.scheme === 'MESH' ? q.mesh : q.hub;
      const alt = d.scheme === 'MESH' ? q.hub : q.mesh;
      if (v.ok && alt.ok) {
        UI.f.pref = v.cost <= alt.cost ? 'COST' : 'TIME';
      }
    }
    UI.msg = null;
    render();
  },
  role: (d) => {
    UI.role = d.v;
    UI.msg = null;
  },
  tab: (d) => {
    UI.tab[UI.role] = d.v;
    UI.msg = null;
  },
  sel: (d) => {
    UI.sel = d.id;
    UI.exampleOpen = false;
  },
  openExample: () => {
    UI.role = 'S';
    UI.tab.S = 'order';
    UI.exampleOpen = true;
    UI.playExample = true;
    UI.msg = null;
  },
  closeExample: () => {
    UI.exampleOpen = false;
  },
  clearOrderFilters: () => {
    UI.orderFilters[UI.role] = { query: '', status: '', point: '' };
  },
  adv: (d) => {
    res(
      call('POST', '/system/time/advance', { hours: (+d.d || 0) * 24 }, uid()),
      'Время сдвинуто на ' + d.d + ' дн. — хранение начислено',
    );
    UI.q = null;
  },
  reset: () => {
    seed();
    UI.scen = null;
    M('Данные сброшены к сидам', true);
  },
  health: () => {
    call('GET', '/health');
  },
  nokey: () => {
    res(call('POST', '/orders', {}), '');
  },
  calc: () => {
    UI.pick = null;
    const f = UI.f;
    try {
      const input = orderPayload();
      const q = quote(it(input.item_id), input.qty, pv(input.target_pvz_id)),
        ok = [q.mesh, q.hub].filter((v) => v.ok);
      q.win = ok.sort((a, c) => (f.pref === 'COST' ? a.cost - c.cost : a.hours - c.hours))[0];
      UI.q = q;
      UI.msg = ok.length ? null : { t: q.block, ok: 0 };
    } catch (e) {
      M(e.message, 0);
    }
  },
  mk: () => {
    const r = call('POST', '/orders', orderPayload(), uid());
    res(r, 'Заказ создан');
    if (r.status < 300) {
      UI.exampleOpen = false;
      UI.sel = r.data.id;
      UI.q = null;
    }
  },
  newitem: () => {
    const result = call('POST', '/items', itemPayload());
    res(result, 'Товар создан');
    if (result.status < 300) UI.itemDraft.name = '';
  },
  supply: () => {
    const r = call('POST', '/supplies', supplyPayload(), uid());
    res(r, 'Завоз объявлен');
  },
  accept: (d) => res(call('POST', `/supplies/${d.id}/accept`, {}, uid()), 'Завоз принят, партия на складе'),
  asm: (d) => {
    UI.keys[d.id] = uid();
    res(call('POST', `/orders/${d.id}/assemble`, {}, UI.keys[d.id]), 'Заказ собран');
  },
  asm2: (d) =>
    res(
      call('POST', `/orders/${d.id}/assemble`, {}, UI.keys[d.id] || uid()),
      'Повторная сборка: двойного списания нет',
    ),
  iss: (d) => res(call('POST', `/orders/${d.id}/issue`, {}, uid()), 'Заказ выдан'),
  ret: (d) => res(call('POST', `/orders/${d.id}/return`, {}, uid()), 'Возврат оформлен'),
  inc: (d) =>
    res(
      call('POST', '/incidents', { order_id: d.id, type: 'WRONG_PVZ' }, uid()),
      'Ошибка зафиксирована, маршрут пересчитан',
    ),
  run: () => {
    const currentData = DB;
    const currentUI = UI;
    try {
      UI = { ...currentUI, f: { ...currentUI.f }, log: [], used: new Set(), keys: {} };
      currentUI.scen = scen();
      const passed = currentUI.scen.filter((scenario) =>
        scenario.st.every((step) => step.ok),
      ).length;
      M(
        `Пройдено сценариев: ${passed} из ${currentUI.scen.length}`,
        passed === currentUI.scen.length,
      );
      currentUI.msg = UI.msg;
    } finally {
      DB = currentData;
      UI = currentUI;
    }
  },
};
function updateField(element) {
  const key = element.dataset.f;
  if (!key || busy) return;
  if (key === 'pvz') UI.pvz = element.value;
  else UI.f[key] = element.value;
  // Смена приоритета не требует нового запроса: пересчитываем выигрышную схему по сохранённому расчёту
  if (key === 'pref' && UI.q && UI.q.mesh && UI.q.hub) {
    const pick = [UI.q.mesh, UI.q.hub].filter((v) => v.ok)
      .sort((a, c) => (UI.f.pref === 'COST' ? a.cost - c.cost : a.hours - c.hours))[0];
    UI.q.win = pick;
    return;
  }
  UI.q = null;
}

function updateOrderFilter(control) {
  UI.orderFilters[UI.role][control.dataset.orderFilter] = control.value;
  $('#order-results').innerHTML = orderListContent();
}

document.addEventListener('input', (event) => {
  if (event.target.matches('input[data-order-filter]')) {
    updateOrderFilter(event.target);
    return;
  }
  const field = event.target.dataset.draft;
  if (field) UI.itemDraft[field] = event.target.value;
  if (event.target.matches('input[data-f]')) {
    updateField(event.target);
    $('#route-preview')?.replaceChildren();
  }
});
document.addEventListener('change', (event) => {
  if (event.target.matches('select[data-order-filter]')) {
    updateOrderFilter(event.target);
    return;
  }
  if (!event.target.matches('select[data-f]') || busy) return;
  updateField(event.target);
  // Явный обработчик приоритета: пересчёт выигрышной схемы и перерисовка карточек
  if (event.target.dataset.f === 'pref' && UI.q && UI.q.mesh && UI.q.hub) {
    const pick = [UI.q.mesh, UI.q.hub].filter((v) => v.ok)
      .sort((a, c) => (UI.f.pref === 'COST' ? a.cost - c.cost : a.hours - c.hours))[0];
    UI.q.win = pick;
    UI.msg = null;
    render();
    return;
  }
  if (apiBase && event.target.dataset.f === 'pvz') refresh();
  else render();
});

document.addEventListener('keydown', (event) => {
  // Enter/Space на фокусируемой карточке схемы — как клик
  if (event.key !== 'Enter' && event.key !== ' ') return;
  const card = event.target.closest('.v[data-a="pick"]');
  if (!card || busy) return;
  event.preventDefault();
  card.click();
});
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-a]');
  if (!button || busy) return;
  const action = button.dataset.a;
  if (action === 'clearOrderFilters') {
    A.clearOrderFilters();
    document.querySelectorAll('[data-order-filter]').forEach((control) => {
      control.value = '';
    });
    $('#order-results').innerHTML = orderListContent();
    return;
  }
  if (apiBase) {
    void remoteAction(action, button.dataset).then(() => {
      if (action === 'sel') $('#order-tracker')?.scrollIntoView({ block: 'start' });
    });
    return;
  }
  try {
    if (['supply', 'accept', 'asm', 'iss', 'ret', 'inc'].includes(action)) UI.q = null;
    A[action]?.(button.dataset);
  } catch (error) {
    M(error.code ? `Ошибка ${error.code}: ${error.message}` : error.message, false);
  }
  render();
  if (action === 'sel' || action === 'openExample') {
    $('#order-tracker')?.scrollIntoView({ block: 'start' });
  }
});
