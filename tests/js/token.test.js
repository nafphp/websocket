import assert from 'node:assert/strict';
import { afterEach, describe, test } from 'node:test';
import { renew, RETRY, STOP } from '../../src/Resources/public/assets/token.js';

/*
 * A client that gives up too easily loses live updates until somebody reloads;
 * one that never gives up asks a host for a token it will never be granted, for
 * as long as the tab is open. Which answer means which is the whole of what this
 * file is about.
 */

const original = globalThis.fetch;

afterEach(() => {
  globalThis.fetch = original;
});

/** @param {ResponseInit & {body?: string}} answer */
function answering(body, init = {}) {
  globalThis.fetch = async (url, options) => {
    globalThis.fetch.sent = { url, options };

    return new Response(body, init);
  };
}

describe('asking for another token', () => {
  test('credentials come back when the application grants them', async () => {
    answering(JSON.stringify({ url: 'wss://localhost:8091', token: 'abc.def', refresh: '/x' }), {
      headers: { 'Content-Type': 'application/json' },
    });

    assert.deepEqual(await renew('/projects/1/socket'), {
      url: 'wss://localhost:8091',
      token: 'abc.def',
    });
  });

  test('the cookie is sent, because it is what identifies the asker', async () => {
    answering(JSON.stringify({ url: 'wss://x', token: 'a.b' }));
    await renew('/projects/1/socket');

    assert.equal(globalThis.fetch.sent.url, '/projects/1/socket');
    assert.equal(globalThis.fetch.sent.options.credentials, 'same-origin');
    assert.equal(globalThis.fetch.sent.options.headers.Accept, 'application/json');
  });

  for (const status of [401, 403, 404]) {
    test(`${status} stops it, because no amount of asking answers differently`, async () => {
      answering('', { status });

      assert.equal(await renew('/projects/1/socket'), STOP);
    });
  }

  test('a server error is a bad moment, not a verdict', async () => {
    answering('', { status: 500 });

    assert.equal(await renew('/projects/1/socket'), RETRY);
  });

  test('no answer at all is worth asking again about', async () => {
    globalThis.fetch = async () => {
      throw new TypeError('Failed to fetch');
    };

    assert.equal(await renew('/projects/1/socket'), RETRY);
  });

  /*
   * The session ended, so the application answered 303 to the sign-in page --
   * and fetch followed it without saying so. A 200 full of HTML is the only
   * trace, which is why the shape of the answer is checked and not just its
   * status.
   */
  test('the sign-in page arriving as a 200 stops it', async () => {
    answering('<!doctype html><title>Anmelden</title>', {
      headers: { 'Content-Type': 'text/html' },
    });

    assert.equal(await renew('/projects/1/socket'), STOP);
  });

  test('an installation with the server switched off stops it', async () => {
    answering(JSON.stringify({ live: false }));

    assert.equal(await renew('/projects/1/socket'), STOP);
  });

  test('an empty token is no token', async () => {
    answering(JSON.stringify({ url: 'wss://x', token: '' }));

    assert.equal(await renew('/projects/1/socket'), STOP);
  });
});
