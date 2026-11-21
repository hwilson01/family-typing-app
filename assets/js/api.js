// Tiny JSON API client. Uses a RELATIVE path so the app works from any
// subfolder on Hostinger (e.g. /projects/typing/). Cookies carry the session.
const BASE = 'api/index.php';

async function call(route, { method = 'GET', body = null, query = {} } = {}) {
  const qs = new URLSearchParams({ r: route, ...query }).toString();
  const opts = { method, credentials: 'same-origin', headers: {} };
  if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res, data;
  try {
    res = await fetch(`${BASE}?${qs}`, opts);
    data = await res.json();
  } catch (e) {
    throw { error: 'network', message: 'Could not reach the server.' };
  }
  if (!data || data.ok !== true) {
    throw { error: data?.error || 'error', message: data?.message || 'Something went wrong.',
            status: res.status, data };
  }
  return data;
}

export const api = {
  get:  (route, query)       => call(route, { query }),
  post: (route, body = {})   => call(route, { method: 'POST', body }),
};
