const TOKEN_KEY = 'poker_token';

async function request(method, path, body) {
  const headers = { 'Content-Type': 'application/json' };
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) headers['X-Auth-Token'] = token;

  let res;
  try {
    res = await fetch('/api' + path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new Error('Cannot reach the server — is the API running?');
  }

  let data = null;
  try {
    data = await res.json();
  } catch {
    data = null;
  }

  if (!res.ok) {
    const msg = (data && data.error) || `Request failed (${res.status})`;
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return data;
}

export const api = {
  setToken(t) { if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY); },
  getToken() { return localStorage.getItem(TOKEN_KEY); },
  logout() { localStorage.removeItem(TOKEN_KEY); },

  register(username, password) { return request('POST', '/auth/register', { username, password }); },
  login(username, password) { return request('POST', '/auth/login', { username, password }); },
  me() { return request('GET', '/me'); },
  buyin(amount) { return request('POST', '/me/buyin', { amount }); },

  rooms() { return request('GET', '/rooms'); },
  state(roomId) { return request('GET', `/rooms/${roomId}/state`); },
  join(roomId, seat) { return request('POST', `/rooms/${roomId}/join`, seat != null ? { seat } : {}); },
  leave(roomId) { return request('POST', `/rooms/${roomId}/leave`); },
  act(roomId, action, amount) { return request('POST', `/rooms/${roomId}/action`, { action, amount: amount ?? null }); },
  tableBuyin(roomId, amount) { return request('POST', `/rooms/${roomId}/buyin`, { amount }); },
  chat(roomId, message) { return request('POST', `/rooms/${roomId}/chat`, { message }); },
};