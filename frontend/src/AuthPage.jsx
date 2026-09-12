import { useState } from 'react';
import { api } from './api';

export default function AuthPage({ onAuthed }) {
  const [mode, setMode] = useState('login');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const res = mode === 'login'
        ? await api.login(username.trim(), password)
        : await api.register(username.trim(), password);
      api.setToken(res.token);
      onAuthed(res.user);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="auth-wrap">
      <div className="auth-card card-panel">
        <div className="auth-emblem">CASINO · ESTD. ROYALE</div>
        <div className="auth-brand">POKER <span className="gold">ROYALE</span></div>
        <div className="auth-sub">No-Limit Texas Hold'em · Live tables · Real players</div>

        <div className="auth-tabs">
          <button className={`auth-tab ${mode === 'login' ? 'active' : ''}`} onClick={() => setMode('login')}>Sign In</button>
          <button className={`auth-tab ${mode === 'register' ? 'active' : ''}`} onClick={() => setMode('register')}>Create Account</button>
        </div>

        <form className="auth-form" onSubmit={submit}>
          <input className="field" placeholder="Username" value={username}
            onChange={(e) => setUsername(e.target.value)} autoComplete="username" maxLength={24} />
          <input className="field" type="password" placeholder="Password" value={password}
            onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
          {error && <div className="auth-err">{error}</div>}
          <button className="btn btn-primary" type="submit" disabled={busy || username.length < 2 || password.length < 4}>
            {busy ? 'Please wait…' : mode === 'login' ? 'Enter the Club' : 'Join the Club'}
          </button>
        </form>

        <div className="auth-suits">♠ ♥ ♦ ♣</div>
      </div>
    </div>
  );
}