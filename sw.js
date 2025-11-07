self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(clients.claim());
});

// Minimal fetch passthrough (no caching yet)
self.addEventListener('fetch', (event) => {
  return; // Let network handle
});