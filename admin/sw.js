// Service worker mínimo do painel admin — só existe pra habilitar instalação
// como PWA (ícone na tela, modo standalone). Sem cache agressivo: dados do
// CRM são sempre dinâmicos, então toda requisição vai direto pra rede — o
// mesmo padrão (e mesmo motivo) do JurídicoSaaS.
const CACHE_NAME = 'admin-pwa-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
