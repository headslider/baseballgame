/* PWA Service Worker for 野球やろうぜ！ */
const CACHE_VERSION = 'yakyu-yarouze-v1065-production';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const STATIC_ASSETS = [
  './',
  './index.html',
  './invite_issue.html',
  './admin_id_issue.html',
  './manifest.webmanifest',
  './api/issue_manifest.php',
  './version.json',
  './assets/styles.css?v=1065',
  './assets/app.js?v=1065',
  './assets/top_background.webp',
  './assets/top_title_mobile.webp',
  './assets/top_title_pc.webp',
  './assets/quiz_master_bg.webp',
  './assets/quiz_master_logo.webp',
  './assets/quiz_master_icon.webp',
  './assets/quiz_master_chair.webp',
  './assets/quiz_master_questions.js?v=1042',
  './data/quiz_master_questions.json',
  './data/quiz_master_titles.json',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/apple-touch-icon.png',
  './assets/icons/maskable-icon-512.png',
  './assets/howto/howto_game_screen.jpg',
  './assets/howto/howto_sample_01.jpg',
  './assets/howto/howto_sample_02.jpg',
  './assets/howto/mistake_review_sample.webp',
  './assets/howto/device_transfer_sample.webp',
  './assets/howto/quiz_master_howto.webp'
];

const NETWORK_FIRST_PATTERNS = [
  /\/data\/game_config\.json(\?.*)?$/,
  /\/data\/quiz_master_questions\.json(\?.*)?$/,
  /\/api\//,
  /\/admin\.html(\?.*)?$/,
  // 段階リリース用プレビュー（秘密URL）は常にサーバーのゲート判定を通すため
  // キャッシュせずネットワーク優先にする。production_hold_enabled.flag による
  // true/false 制御がキャッシュ済み端末でも即時に効くようにする。
  /\/index-preview-[a-z0-9]+\.php(\?.*)?$/,
  // メンテ切替フラグ自体は常に最新を取得する（アプリ側のライフ無制限判定や
  // ページ遷移戦略の判定が古い値でキャッシュされないようにする）。
  /\/production_hold_enabled\.flag$/
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => ![STATIC_CACHE, RUNTIME_CACHE].includes(key))
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

function isNetworkFirst(request) {
  const url = new URL(request.url);
  return NETWORK_FIRST_PATTERNS.some(pattern => pattern.test(url.pathname));
}

async function networkFirst(request) {
  const cache = await caches.open(RUNTIME_CACHE);
  try {
    const response = await fetch(request, { cache: 'no-store' });
    if (request.method === 'GET' && response && response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) return cached;
    throw error;
  }
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (request.method === 'GET' && response && response.ok) {
    const cache = await caches.open(RUNTIME_CACHE);
    cache.put(request, response.clone());
  }
  return response;
}

// production_hold_enabled.flag を読み、メンテナンス（公開停止）中かを判定する。
//   true / 1 / on / yes → メンテ中（hold有効）
//   false / 空 / 不在 / 取得失敗 → 公開（hold無効）
// SWスクリプトと同階層（/baseball/ または /baseball_test/）のフラグを参照する。
async function isHoldEnabled() {
  try {
    const res = await fetch('production_hold_enabled.flag', { cache: 'no-store' });
    if (!res || !res.ok) return false;
    const v = (await res.text()).trim().toLowerCase();
    return v === 'true' || v === '1' || v === 'on' || v === 'yes';
  } catch (e) {
    return false;
  }
}

self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // 秘密URL・API・設定JSON等は常にネットワーク優先（ゲートを必ず評価する）。
  if (isNetworkFirst(request)) {
    event.respondWith(networkFirst(request));
    return;
  }

  // ページ遷移（HTMLナビゲーション = index.html 等）は
  // production_hold_enabled.flag で配信戦略を切り替える：
  //   - hold有効（メンテ中, flag=true） → networkFirst
  //       index.html を cacheFirst にすると旧SWがキャッシュ済みの実アプリを
  //       返してしまう（ゲート漏れ）ため、メンテ中は常に最新を配信する。
  //   - hold無効（公開後, flag=false/不在） → cacheFirst
  //       通常の PWA 高速表示に戻す。
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      const hold = await isHoldEnabled();
      return hold ? networkFirst(request) : cacheFirst(request);
    })());
    return;
  }

  event.respondWith(cacheFirst(request));
});


self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});


self.addEventListener('push', event => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = { title: '野球やろうぜ！', body: event.data ? event.data.text() : '新しいお知らせがあります。' };
  }
  const title = payload.title || '野球やろうぜ！';
  const options = {
    body: payload.body || '新しいお知らせがあります。',
    icon: payload.icon || 'assets/icons/icon-192.png',
    badge: payload.badge || 'assets/icons/icon-192.png',
    data: { url: payload.url || './' },
    tag: payload.tag || 'yakyu-yarouze-notice',
    renotify: !!payload.renotify
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const client of list) {
        if ('focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

self.addEventListener('pushsubscriptionchange', event => {
  // 再購読はアプリ画面で行う。古い購読が切れた場合は設定画面から再度「通知を受け取る」を押してください。
});
