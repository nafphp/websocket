/*
 * The browser end of naf/websocket.
 *
 * It listens and reports; it never renders. A message says which channel and
 * which revision, and whoever cares fetches the new state through the
 * application's ordinary path -- which is why nothing here has to be trusted
 * with what a card looks like or who may see one.
 *
 * Everything it needs is in the page: where to connect and a token that names
 * the channels. Without either, this module does nothing at all, which is what
 * an installation with the server switched off should get.
 */
const settings = document.querySelector('script[data-naf-websocket]');
const config = settings ? JSON.parse(settings.textContent || '{}') : null;

/** Backing off rather than hammering: a server that is restarting needs a moment. */
const DELAYS = [500, 1000, 2000, 5000, 10000, 20000];

let socket = null;
let attempt = 0;
let timer = null;
let closed = false;

function announce(name, detail) {
  document.dispatchEvent(new CustomEvent(name, { detail }));
}

function connect() {
  if (closed || !config?.url || !config?.token) return;

  socket = new WebSocket(`${config.url}?token=${encodeURIComponent(config.token)}`);

  socket.addEventListener('open', () => {
    attempt = 0;
    announce('naf:websocket-open', {});
  });

  socket.addEventListener('message', (event) => {
    let message;
    try {
      message = JSON.parse(event.data);
    } catch {
      return;
    }
    // The server says what happened; nothing here decides what it means.
    announce('naf:websocket-message', message);
  });

  socket.addEventListener('close', () => {
    socket = null;
    if (closed) return;
    // A token is short-lived, so a reconnect needs a fresh page-issued one.
    // Reloading the page is not that; asking for one is, and that is the next
    // piece. Until then a dropped connection stays dropped after its token
    // has expired, and the application falls back to what it did before.
    const delay = DELAYS[Math.min(attempt++, DELAYS.length - 1)];
    timer = setTimeout(connect, delay);
    announce('naf:websocket-closed', { retryIn: delay });
  });

  socket.addEventListener('error', () => socket?.close());
}

// A page being left should not keep retrying on the way out.
addEventListener('pagehide', () => {
  closed = true;
  clearTimeout(timer);
  socket?.close();
});

if (config?.url && config?.token) connect();

export function live() {
  return socket?.readyState === WebSocket.OPEN;
}
