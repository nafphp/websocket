import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';

/*
 * The one door that opens inwards.
 *
 * Every other message in this package travels server to browser, which is what
 * makes the browser end safe to be careless with: it is told that something
 * changed and fetches the rest through the application. Presence is the
 * exception -- where somebody is looking exists only in their own browser -- so
 * a page may say that one thing, and this is the door it goes through.
 *
 * Two things are worth holding. That what a page says arrives as it was given,
 * because the far end reads it as JSON and a page that has to encode its own
 * would eventually encode it differently. And that the door stays shut when
 * there is nothing behind it: a page dispatching at a closed socket must be
 * dropped rather than throw, because presence is a courtesy and the next thing
 * said replaces whatever was missed. That second one is easy to get wrong and
 * impossible to notice -- the only symptom is an error in a console nobody has
 * open.
 */

const sent = [];
let socket;

before(async () => {
  // The module reads the page and connects through the platform. Given neither,
  // there is nothing to stand in for -- so both are put here before it is
  // imported, which is the only moment it looks at either.
  const page = new EventTarget();
  page.querySelector = (selector) =>
    selector === 'script[data-naf-websocket]'
      ? { textContent: JSON.stringify({ url: 'wss://host', token: 'a.b', refresh: '/r' }) }
      : null;

  globalThis.document = page;
  globalThis.addEventListener = () => {};
  globalThis.WebSocket = class {
    static OPEN = 1;

    constructor() {
      this.readyState = 1;
      socket = this;
    }

    addEventListener() {}

    send(text) {
      sent.push(text);
    }
  };

  await import('../../src/Resources/public/assets/websocket.js');
  // connect() is asynchronous, so the socket exists a tick after the import.
  await new Promise((resolve) => setTimeout(resolve, 0));
});

after(() => {
  delete globalThis.document;
  delete globalThis.addEventListener;
  delete globalThis.WebSocket;
});

describe('saying something back', () => {
  test('what a page says goes out as it was given', () => {
    sent.length = 0;
    document.dispatchEvent(new CustomEvent('naf:websocket-say', { detail: { at: '#NAF-2' } }));

    assert.deepEqual(sent, ['{"at":"#NAF-2"}']);
  });

  test('a page that says nothing in particular still says something valid', () => {
    sent.length = 0;
    document.dispatchEvent(new CustomEvent('naf:websocket-say'));

    assert.deepEqual(sent, ['{}'], 'the server would have to parse an undefined');
  });

  test('nothing is sent while the connection is not open', () => {
    socket.readyState = 3;
    sent.length = 0;
    document.dispatchEvent(new CustomEvent('naf:websocket-say', { detail: { at: 'board' } }));
    socket.readyState = 1;

    assert.deepEqual(sent, [], 'a page spoke into a closed socket');
  });

  test('a closed connection is dropped rather than raised at whoever asked', () => {
    socket.readyState = 3;

    assert.doesNotThrow(() => {
      document.dispatchEvent(new CustomEvent('naf:websocket-say', { detail: { at: 'board' } }));
    });

    socket.readyState = 1;
  });
});
