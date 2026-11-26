let deliveryMap = null;
let mapInput = null;
let stopDeliveryMap = null;
const routerEndpoint = new URLSearchParams(location.search).get('router');
const recentRoads = new Map();
const osmCredit =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

function coordinates(point) {
  if (!point) return null;
  const [latitude, longitude] = Array.isArray(point) ? point : [point.lat, point.lng];
  if (latitude == null || longitude == null || latitude === '' || longitude === '')
    return null;
  const lat = Number(latitude);
  const lng = Number(longitude);
  return Math.abs(lat) <= 85 && Math.abs(lng) <= 180 ? [lat, lng] : null;
}

const pointKey = (point) => point.map((value) => value.toFixed(5)).join(',');
const distinctSteps = (points) =>
  points.filter((point, index) => !index || pointKey(point) !== pointKey(points[index - 1]));

function normalizeRoad(geometry) {
  const points = Array.isArray(geometry)
    ? geometry.map(coordinates)
    : geometry?.type === 'LineString' && Array.isArray(geometry.coordinates)
      ? geometry.coordinates.map((point) =>
          Array.isArray(point) ? coordinates([point[1], point[0]]) : null,
        )
      : [];
  return points.length >= 2 && points.every(Boolean) ? distinctSteps(points) : [];
}

function decodeDemoRoad(encoded) {
  const points = [];
  let cursor = 0;
  let lat = 0;
  let lng = 0;
  const delta = () => {
    let value = 0;
    let shift = 0;
    let chunk;
    do {
      chunk = encoded.charCodeAt(cursor++) - 63;
      value |= (chunk & 31) << shift;
      shift += 5;
    } while (chunk >= 32);
    return value & 1 ? ~(value >> 1) : value >> 1;
  };
  while (cursor < encoded.length) {
    lat += delta();
    lng += delta();
    points.push([lat / 1e6, lng / 1e6]);
  }
  return points;
}

function roadForDemo(waypoints) {
  const points = [];
  for (let index = 1; index < waypoints.length; index++) {
    const leg = demoRoads[pointKey(waypoints[index - 1]) + ';' + pointKey(waypoints[index])];
    if (!leg) return [];
    points.push(...decodeDemoRoad(leg));
  }
  return distinctSteps(points);
}

async function requestRoad(waypoints, signal) {
  const key = waypoints.map(pointKey).join(';');
  if (recentRoads.has(key)) return recentRoads.get(key);
  const url = new URL(routerEndpoint, location.href);
  if (!['http:', 'https:'].includes(url.protocol))
    throw Error('Неверный адрес маршрутизатора');
  const locations = waypoints.map(([lat, lng]) => [lng, lat].join(',')).join(';');
  url.pathname = url.pathname.replace(/\/+$/, '') + '/' + locations;
  url.searchParams.set('geometries', 'geojson');
  url.searchParams.set('overview', 'full');
  url.searchParams.set('steps', 'false');
  const response = await fetch(url, {
    signal: AbortSignal.any([signal, AbortSignal.timeout(9000)]),
  });
  if (!response.ok) throw Error('Маршрутизатор недоступен');
  const result = await response.json();
  if (result.code !== 'Ok') throw Error('Проезд не найден');
  const points = normalizeRoad(result.routes?.[0]?.geometry);
  if (points.length < 2) throw Error('Нет дорожной геометрии');
  if (recentRoads.size >= 24) recentRoads.delete(recentRoads.keys().next().value);
  recentRoads.set(key, points);
  return points;
}

function measureRoad(points) {
  const positions = [0];
  for (let index = 1; index < points.length; index++) {
    positions.push(
      positions[index - 1] + L.latLng(points[index - 1]).distanceTo(points[index]),
    );
  }
  return { points, positions, meters: positions.at(-1) };
}

function carPosition(road, fraction) {
  if (!road.meters) return road.points[0];
  const target = road.meters * fraction;
  let low = 0;
  let high = road.points.length - 1;
  while (high - low > 1) {
    const mid = (low + high) >> 1;
    if (road.positions[mid] <= target) low = mid;
    else high = mid;
  }
  const [lat, lng] = road.points[low];
  const [nextLat, nextLng] = road.points[high];
  const segment = road.positions[high] - road.positions[low];
  const offset = (target - road.positions[low]) / segment;
  return [lat + (nextLat - lat) * offset, lng + (nextLng - lng) * offset];
}

const truckModel = `<span class="truck-model" aria-hidden="true">
        <span class="truck-shadow"></span>
        ${['cargo', 'cab']
    .map(
      (part) => `<span class="truck-box truck-${part}">
          <span class="truck-face roof"></span>
          <span class="truck-face side near">${part === 'cargo' ? 'WB' : ''}</span>
          <span class="truck-face side far">${part === 'cargo' ? 'WB' : ''}</span>
          <span class="truck-face front"></span>
          <span class="truck-face rear"></span>
        </span>`,
    )
    .join('')}
        <span class="truck-wheel back near"></span><span class="truck-wheel forward near"></span>
        <span class="truck-wheel back far"></span><span class="truck-wheel forward far"></span>
      </span>`;

function mountDeliveryMap() {
  const element = $('#delivery-geography');
  if (!element || !mapInput) return;
  const { order, stops, progress } = mapInput;
  const note = $('#delivery-map-note');
  const tilesNote = $('#delivery-tiles-note');
  const drive = $('#delivery-drive');
  const driveNote = $('#delivery-drive-note');
  const supplied = normalizeRoad(order.route_geometry);
  const resolveStop = (stop) =>
    coordinates(stop) || coordinates(DB.pvz.find((point) => point.name === stop?.n));
  const sellerDropOff = stops[0]?.kind === 'SELLER';
  const startIndex = sellerDropOff ? 1 : 0;
  const firstStop = stops[startIndex];
  const source = firstStop ? DB.pvz.find((point) => point.name === firstStop.n) : pv(order.source_pvz_id);
  const target = pv(order.target ?? order.target_pvz_id);
  const start = coordinates(source) || resolveStop(firstStop) || supplied[0];
  const end = coordinates(target) || resolveStop(stops.at(-1)) || supplied.at(-1);
  if (!start || !end) {
    element.hidden = true;
    note.textContent = 'Для карты нужны координаты ПВЗ отправления и выдачи.';
    return;
  }

  const middle = stops
    .slice(startIndex + 1, -1)
    .map((stop) => ({
      name: stop.n,
      address: stop.address ?? DB.pvz.find((point) => point.name === stop.n)?.address,
      label: /хаб/i.test(stop.n ?? '') ? 'Хаб' : stop.n || 'Остановка',
      stub: Boolean(stop.is_stub || stop.h),
      point: resolveStop(stop),
    }))
    .filter((stop) => stop.point);
  const waypoints = distinctSteps([start, ...middle.map((stop) => stop.point), end]);
  const map = L.map(element, {
    scrollWheelZoom: true,
    touchZoom: true,
    zoomAnimation: false,
    fadeAnimation: false,
    markerZoomAnimation: false,
  }).setView(start, 13);
  deliveryMap = map;
  map.attributionControl.setPrefix('<a href="https://leafletjs.com/">Leaflet</a>');
  const controller = new AbortController();
  let frame = 0;
  stopDeliveryMap = () => {
    controller.abort();
    cancelAnimationFrame(frame);
  };

  const bounds = L.latLngBounds(waypoints);
  const fit = () =>
    map.fitBounds(bounds, {
      paddingTopLeft: [38, 60],
      paddingBottomRight: [38, 42],
      maxZoom: 15,
      animate: false,
    });
  const pins = [
    {
      label: sellerDropOff ? 'ПВЗ приёмки' : 'Отправление',
      name: source?.name ?? firstStop?.n,
      address: source?.address ?? firstStop?.address,
      point: start,
    },
    ...middle,
    {
      label: 'Выдача',
      name: target?.name ?? stops.at(-1)?.n,
      address: target?.address ?? stops.at(-1)?.address,
      point: end,
    },
  ];
  const uniquePins = new Map();
  for (const stop of pins) {
    const key = pointKey(stop.point);
    const existing = uniquePins.get(key);
    if (existing) {
      existing.label += ' и ' + stop.label.toLowerCase();
      existing.destination ||= stop.label === 'Выдача';
    } else {
      uniquePins.set(key, { ...stop, destination: stop.label === 'Выдача' });
    }
  }
  for (const stop of uniquePins.values()) {
    const label = document.createElement('span');
    label.textContent = stop.label;
    const popup = document.createElement('div');
    const heading = document.createElement('strong');
    heading.textContent = stop.name || stop.label;
    const address = document.createElement('div');
    address.textContent =
      stop.address || (stop.stub ? 'Демонстрационная точка' : 'Адрес не указан');
    popup.append(heading, address);
    const pin = L.circleMarker(stop.point, {
      radius: 8,
      color: '#fff',
      weight: 2,
      fillColor: stop.destination ? '#c71982' : '#8c1a9e',
      fillOpacity: 1,
    })
      .bindTooltip(label, {
        permanent: true,
        direction: 'bottom',
        offset: [0, 8],
        className: 'delivery-stop-label',
      })
      .bindPopup(popup)
      .addTo(map);
    const path = pin.getElement();
    path.setAttribute('tabindex', '0');
    path.setAttribute('role', 'button');
    path.setAttribute('aria-label', stop.label + ': ' + (stop.name ?? ''));
    path.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        pin.openPopup();
      }
    });
  }

  const reset = L.control({ position: 'topright' });
  reset.onAdd = () => {
    const button = L.DomUtil.create('button', 'b fit-delivery-map');
    button.type = 'button';
    button.textContent = 'Весь маршрут';
    L.DomEvent.disableClickPropagation(button);
    L.DomEvent.on(button, 'click', fit);
    return button;
  };
  reset.addTo(map);
  fit();

  const truck = L.divIcon({
    className: 'map-vehicle-icon',
    html: truckModel,
    iconSize: [70, 68],
    iconAnchor: [35, 34],
  });
  const marker = L.marker(progress >= 100 ? end : start, {
    icon: truck,
    interactive: false,
  }).addTo(map);
  function showRoad(points, sourceLabel) {
    const road = measureRoad(points);
    const placeTruck = (fraction) => marker.setLatLng(carPosition(road, fraction));
    if (!road.meters) {
      placeTruck(0);
      note.textContent = 'Отправление и выдача в одном ПВЗ. Поездка не нужна.';
      return;
    }

    const pane = map.createPane('delivery-road');
    pane.style.zIndex = '390';
    const lineStyle = { pane: 'delivery-road', interactive: false };
    L.polyline(points, { ...lineStyle, color: '#fff', weight: 9, opacity: 0.92 }).addTo(map);
    L.polyline(points, { ...lineStyle, color: '#921ca4', weight: 5, opacity: 0.95 }).addTo(
      map,
    );
    bounds.extend(L.latLngBounds(points));
    fit();
    placeTruck(progress / 100);
    const distance =
      road.meters >= 1000
        ? (road.meters / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' км'
        : Math.round(road.meters) + ' м';
    note.textContent = `${sourceLabel} · ${distance}. Положение машины рассчитано по статусу.`;

    drive.hidden = false;
    const stopDrive = () => {
      cancelAnimationFrame(frame);
      frame = 0;
      marker.getElement().classList.remove('is-driving');
      drive.textContent = 'Показать проезд';
      drive.setAttribute('aria-pressed', 'false');
      driveNote.textContent = '';
      placeTruck(progress / 100);
    };
    drive.addEventListener(
      'click',
      () => {
        if (frame) return stopDrive();
        marker.getElement().classList.add('is-driving');
        drive.textContent = 'Остановить проезд';
        drive.setAttribute('aria-pressed', 'true');
        driveNote.textContent = 'Демонстрация движения по маршруту.';
        const begins = performance.now();
        const advance = (time) => {
          const fraction = Math.min(1, Math.max(0, (time - begins) / 9500));
          placeTruck(fraction);
          if (fraction < 1) frame = requestAnimationFrame(advance);
          else stopDrive();
        };
        placeTruck(0);
        frame = requestAnimationFrame(advance);
      },
      { signal: controller.signal },
    );
    if (UI.playExample) {
      UI.playExample = false;
      drive.click();
    }
  }

  const demo = !apiBase && supplied.length < 2 ? roadForDemo(waypoints) : [];
  if (supplied.length >= 2) {
    showRoad(supplied, 'Дорожный маршрут из заказа');
  } else if (demo.length >= 2) {
    map.attributionControl.addAttribution(osmCredit);
    map.attributionControl.addAttribution(
      '<a href="https://routing.openstreetmap.de/about.html">OSRM/FOSSGIS</a> · <a href="https://www.openstreetmap.org/fixthemap">Исправить карту</a>',
    );
    showRoad(demo, 'Дорожный маршрут демо');
  } else if (waypoints.length < 2) {
    showRoad([start], '');
  } else if (routerEndpoint) {
    note.textContent = 'Строим маршрут по дорожной сети…';
    void requestRoad(waypoints, controller.signal)
      .then((points) => {
        if (!controller.signal.aborted) showRoad(points, 'Дорожный маршрут OSRM');
      })
      .catch(() => {
        if (!controller.signal.aborted)
          note.textContent =
            'Маршрутизатор не вернул путь. На карте показаны точки доставки.';
      });
  } else {
    note.textContent = 'Дорожный маршрут пока недоступен. На карте показаны точки доставки.';
  }

  if (['http:', 'https:'].includes(location.protocol)) {
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: osmCredit,
    });
    let hasTiles = false;
    tiles.on('tileerror', () => {
      if (!hasTiles) {
        tilesNote.hidden = false;
        tilesNote.textContent = 'Подложка с улицами недоступна.';
      }
    });
    tiles.on('tileload', () => {
      hasTiles = true;
      tilesNote.hidden = true;
    });
    tiles.addTo(map);
  } else {
    tilesNote.hidden = false;
    tilesNote.textContent = 'Для подложки с названиями улиц открой страницу по HTTP.';
  }
}

function deliveryTracker(order) {
  const milestones = {
    CREATED: [0, 'Ожидает сборки'],
    ASSEMBLED: [50, 'Собран · ожидает выдачи'],
    ISSUED: [100, 'Выдан покупателю'],
    RETURNED: [100, 'Возвращён в ПВЗ выдачи'],
  };
  const [progress, status] = Object.hasOwn(milestones, order.status)
    ? milestones[order.status]
    : [0, order.status || 'Статус уточняется'];
  const stops = Array.isArray(order.segs) ? order.segs.filter(Boolean) : [];
  if (!stops.length && !order.route_geometry && !order.source_pvz_id) return '';
  mapInput = { order, stops, progress };
  const stopLabels = stops
    .map((stop) => {
      const stub = stop.is_stub || stop.h ? ' · заглушка' : '';
      return `<li>${esc(stop.n)}${stub}</li>`;
    })
    .join('');
  const hours = Number(order.hours);
  const sla = Number(order.sla);
  const hasSla =
    order.hours != null &&
    order.sla != null &&
    Number.isFinite(hours) &&
    Number.isFinite(sla) &&
    sla > 0 &&
    hours >= 0;
  const difference = Number(order.base) - Number(order.cost);
  const comparison =
    order.base == null || order.cost == null || !Number.isFinite(difference)
      ? ''
      : Math.abs(difference) < 0.005
        ? 'Стоимость на уровне базового маршрута'
        : `${difference > 0 ? 'Дешевле' : 'Дороже'} базового маршрута на ${rub(Math.abs(difference))}`;
  return `<div class="delivery-map">
          <p class="map-caption">Отслеживание по статусу заказа · без GPS</p>
          <div class="map-status-row">
            <strong>${esc(status)}</strong>
            <button id="delivery-drive" class="b" type="button" aria-pressed="false" hidden>Показать проезд</button>
          </div>
          <div id="delivery-geography" class="delivery-geography" role="region" aria-label="Карта маршрута заказа"></div>
          <p id="delivery-map-note" class="map-caption" role="status"></p>
          <p id="delivery-tiles-note" class="map-caption" hidden></p>
          <p id="delivery-drive-note" class="map-caption" role="status"></p>
          <div class="map-track" style="--progress:${progress}%" aria-hidden="true"></div>
          <ol class="map-stops" aria-label="Этапы маршрута">${stopLabels}</ol>
        </div>
        <div class="tracking-metrics">
          ${hasSla ? `<p>${chip(hours <= sla ? 'В пределах SLA' : 'Риск превышения SLA', hours <= sla ? 'ok' : 'er')} Расчёт: ${hours.toFixed(1)} ч из ${sla} ч</p>` : ''}
          ${comparison ? `<p class="mu">${comparison}</p>` : ''}
        </div>`;
}
