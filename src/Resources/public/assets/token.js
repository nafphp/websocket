/*
 * Asking the application for another token.
 *
 * A token is deliberately short-lived, so a connection that was gone for longer
 * than its lifetime cannot come back with the one its page was rendered with.
 * Only the application knows whether this person may still hear this channel, so
 * only the application can issue the next one -- and what comes back from asking
 * is either credentials or a reason to stop asking.
 *
 * The reasons are worth separating. A request that never arrived says nothing
 * about whether it would have been granted, and such a request comes back. An
 * answer saying no is an answer: asking again cannot change it, and a client
 * that keeps asking is a client hammering a host over something only the person
 * can resolve, by loading a page.
 */

/** Ask again later: nobody answered, or whoever did was not in a state to. */
export const RETRY = 'retry';

/** Stop: the application answered, and the answer was no. */
export const STOP = 'stop';

/**
 * @param {string} url Where this host issues tokens; it comes from the page.
 * @returns {Promise<{url: string, token: string}|'retry'|'stop'>}
 */
export async function renew(url) {
  let response;
  try {
    response = await fetch(url, {
      headers: { Accept: 'application/json' },
      // The cookie is the whole point: this is the one request in the exchange
      // that can prove who is asking.
      credentials: 'same-origin',
    });
  } catch {
    // No answer at all -- offline, or the host is restarting. Both come back.
    return RETRY;
  }

  // Signed out, or no longer a member of this board. Nothing a retry reaches.
  if ([401, 403, 404].includes(response.status)) return STOP;
  // Anything else that failed is the host having a bad moment, not a verdict.
  if (!response.ok) return RETRY;

  let answer;
  try {
    answer = await response.json();
  } catch {
    // An answer that is not JSON is the sign-in page: the session ended and the
    // redirect to it was followed silently, because that is what fetch does.
    return STOP;
  }

  // No token in a perfectly good answer means the server was switched off. That
  // is a decision, so it is treated like one.
  if (typeof answer?.token !== 'string' || answer.token === '') return STOP;
  if (typeof answer?.url !== 'string' || answer.url === '') return STOP;

  return { url: answer.url, token: answer.token };
}
