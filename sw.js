// Define um nome e versão para o nosso cache
const CACHE_NAME = 'painel-financeiro-v1.1';

// Lista completa de arquivos e recursos essenciais para o App Shell
const urlsToCache = [
  './',
  './index.html',
  './style.css',
  './auth.js',
  // Arquivos de terceiros (CDN)
  'https://cdn.tailwindcss.com',
  'https://code.jquery.com/jquery-3.6.0.min.js',
  'https://cdn.jsdelivr.net/npm/chart.js',
  'https://cdn.jsdelivr.net/npm/idb@7/build/umd.js',
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
  // Ícones (adicione os nomes corretos dos seus arquivos de ícone)
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/maskable-icon.png'
];

// --- 1. INSTALAÇÃO DO SERVICE WORKER E CACHE DO APP SHELL ---

self.addEventListener('install', (event) => {
  console.log('[Service Worker] Instalando...');
  // Espera a Promise resolver para garantir que o cache foi populado.
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[Service Worker] Cache aberto. Adicionando App Shell ao cache.');
        return cache.addAll(urlsToCache);
      })
      .catch(error => {
        console.error('[Service Worker] Falha ao adicionar arquivos ao cache:', error);
      })
  );
});

// --- 2. ESTRATÉGIA DE CACHE (CACHE FIRST) ---

self.addEventListener('fetch', (event) => {
  // Ignora requisições que não são GET (ex: POST para a API)
  if (event.request.method !== 'GET') {
    return;
  }
  
  // Para as requisições na nossa lista de cache, usamos a estratégia "Cache First"
  if (urlsToCache.includes(new URL(event.request.url).pathname) || urlsToCache.includes(event.request.url)) {
    event.respondWith(
      caches.match(event.request)
        .then((response) => {
          // Se encontrar no cache, retorna a resposta do cache.
          if (response) {
            return response;
          }
          // Se não encontrar, faz a requisição à rede.
          return fetch(event.request);
        })
    );
  }
  // Para outras requisições (ex: API), a estratégia padrão "Network First" será usada.
});


// --- 3. SINCRONIZAÇÃO EM SEGUNDO PLANO ---

self.addEventListener('sync', (event) => {
  console.log('[Service Worker] Evento de sync recebido!', event.tag);
  if (event.tag === 'sync-data') {
    event.waitUntil(processSyncQueue());
  }
});

function processSyncQueue() {
  return new Promise((resolve, reject) => {
    // A biblioteca 'idb' não está disponível aqui, então usamos a API nativa do IndexedDB.
    const openRequest = indexedDB.open('financial-dashboard-db', 1);

    openRequest.onerror = (event) => {
      console.error('[Service Worker] Erro ao abrir IndexedDB para sync:', event.target.error);
      reject(event.target.error);
    };

    openRequest.onsuccess = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('sync-queue')) {
        console.warn('[Service Worker] Object store "sync-queue" não encontrado.');
        resolve();
        return;
      }
      
      const tx = db.transaction('sync-queue', 'readwrite');
      const store = tx.objectStore('sync-queue');
      const getAllRequest = store.getAll();

      getAllRequest.onerror = (event) => {
        console.error('[Service Worker] Erro ao ler a fila de sync:', event.target.error);
        reject(event.target.error);
      };

      getAllRequest.onsuccess = () => {
        const actions = getAllRequest.result;
        if (actions.length === 0) {
          console.log('[Service Worker] Fila de sincronização vazia.');
          resolve();
          return;
        }

        console.log(`[Service Worker] Processando ${actions.length} ações da fila.`);

        fetch('api.php?action=processSyncQueue', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ actions: actions }),
        })
        .then(response => response.json())
        .then(data => {
          if (data.success && data.processed_ids) {
            console.log('[Service Worker] Ações processadas com sucesso no servidor:', data.processed_ids);
            const deleteTx = db.transaction('sync-queue', 'readwrite');
            const deleteStore = deleteTx.objectStore('sync-queue');
            data.processed_ids.forEach(id => deleteStore.delete(id));
            resolve();
          } else {
            throw new Error(data.message || 'Falha ao processar a fila no servidor.');
          }
        })
        .catch(err => {
          console.error('[Service Worker] Erro durante o fetch da sincronização:', err);
          reject(err);
        });
      };
    };
  });
}

// --- ATIVAÇÃO E LIMPEZA DE CACHES ANTIGOS ---
self.addEventListener('activate', (event) => {
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheWhitelist.indexOf(cacheName) === -1) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});