/*
 * The browser end of naf/websocket.
 *
 * It listens and reports; it never renders. A message says which channel and
 * which revision, and whoever cares fetches the new state through the
 * application's ordinary path -- which is why nothing here has to be trusted
 * with what a card looks like or who may see one.
 *
 * Everything it needs to start is in the page: where to connect, a token that
 * names the channels, and where to ask for the next one. Without the first two,
 * this module does nothing at all, which is what an installation with the server
 * switched off should get.
 */
import { renew, RETRY, STOP } from './token.js';

const settings = document.querySelector('script[data-naf-websocket]');
const config = settings ? JSON.parse(settings.textContent || '{}') : null;

/** Backing off rather than hammering: a server that is restarting needs a moment. */
const DELAYS = [500, 1000, 2000, 5000, 10000, 20000];

let socket = null;
let attempt = 0;
let timer = null;
let closed = false;

/*
 * Used once and then thrown away. The token in the page opens the first
 * connection; every attempt after that asks for its own, because the rendered
 * one is the first thing about a page to expire -- and asking is also how a
 * client finds out it no longer may.
 */
let credentials = config?.url && config?.token ? { url: config.url, token: config.token } : null;

function announce(name, detail) {
  document.dispatchEvent(new CustomEvent(name, { detail }));
}

/** Done for good: no delay is announced, because nothing is waiting any more. */
function stop() {
  closed = true;
  announce('naf:websocket-closed', { retryIn: null });
}

function later() {
  const delay = DELAYS[Math.min(attempt++, DELAYS.length - 1)];
  timer = setTimeout(connect, delay);
  announce('naf:websocket-closed', { retryIn: delay });
}

async function connect() {
  if (closed) return;

  if (credentials === null) {
    if (!config?.refresh) {
      stop();

      return;
    }

    const answer = await renew(config.refresh);
    // Asking took a moment, and a page can be left during it.
    if (closed) return;
    if (answer === STOP) {
      stop();

      return;
    }
    if (answer === RETRY) {
      later();

      return;
    }
    credentials = answer;
  }

  socket = new WebSocket(`${credentials.url}?token=${encodeURIComponent(credentials.token)}`);

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
    // Whatever these were worth, they are spent: the next attempt asks.
    credentials = null;
    if (closed) return;
    later();
  });

  socket.addEventListener('error', () => socket?.close());
}

/*
 * The one thing a page may say back.
 *
 * Everything above is one-way on purpose: the server says what changed and the
 * page fetches it through the application, so nothing here has to be trusted.
 * Presence is the exception the server makes -- where somebody is looking is a
 * fact only their own browser has -- and this is the door it comes through.
 *
 * An event rather than an exported function, because that is already how this
 * module talks: a page that wants to be told listens for `naf:websocket-open`,
 * and a page that wants to speak dispatches this. Neither needs to know where
 * the file lives, which for a package served under /plugins/ is worth keeping.
 *
 * Dropped when there is no connection, and that is the whole error handling:
 * presence is a courtesy. The next thing said replaces what was missed.
 */
document.addEventListener('naf:websocket-say', (event) => {
  if (socket?.readyState !== WebSocket.OPEN) return;
  socket.send(JSON.stringify(event.detail ?? {}));
});

// A page being left should not keep retrying on the way out.
addEventListener('pagehide', () => {
  closed = true;
  clearTimeout(timer);
  socket?.close();
});

if (credentials !== null) connect();

export function live() {
  return socket?.readyState === WebSocket.OPEN;
}
