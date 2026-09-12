import { useEffect, useState } from 'react';
import { api } from './api';

export default function Lobby({ user, onPlay, onLogout }) {
  const [rooms, setRooms] = useState([]);
  const [me, setMe] = useState(user);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [addAmt, setAddAmt] = useState(5000);

  const load = async () => {
    try {
      const d = await api.rooms();
      setRooms(d.rooms || []);
      setMe(await api.me());
    } catch (e) {
      setError(e.message);
    }
  };

  useEffect(() => { load(); }, []);

  const addChips = async () => {
    setBusy(true);
    try {
      const u = await api.buyin(Math.max(1, addAmt));
      setMe(u);
      await load();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const suit = ['♠', '♥', '♦', '♣', '★', '❀'];

  return (
    <div>
      <div className="topbar">
        <span className="topbar-brand">Poker <span style={{ color: 'var(--gold)' }}>Royale</span></span>
        <span className="gold-text">The Lobby</span>
        <div className="topbar-spacer" />
        <span className="chips-badge">
          <span className="chip" style={{ width: 22, height: 22, background: 'radial-gradient(circle at 35% 30%, var(--gold-light), var(--gold))' }} />
          {me?.username} · {Number(me?.chips || 0).toLocaleString('en-US')}
        </span>
        <button className="btn" onClick={onLogout}>Logout</button>
      </div>

      <div className="lobby">
        <div className="lobby-title">Choose your battle station</div>
        <h1 className="lobby-head">Pick a <span className="gold">Table</span></h1>

        {error && <div className="auth-err" style={{ marginBottom: 16 }}>{error}</div>}

        <div className="rooms-grid">
          {rooms.map((r) => (
            <div className="room-card card-panel" key={r.id}>
              <div className="room-tag">No-Limit Hold'em</div>
              <div className="room-name">{r.name}</div>
              <div className="room-blinds">
                {r.small_blind}/{r.big_blind}
                <small>BLINDS</small>
              </div>
              <div className="room-meta">
                <span>Buy-in: {Number(r.min_buyin).toLocaleString()} – {Number(r.max_buyin).toLocaleString()}</span>
                <div className="room-seats">
                  {Array.from({ length: r.max_players }).map((_, i) => (
                    <span key={i} className={`mini-seat ${i < (r.seated || 0) ? 'filled' : ''}`}>
                      {i < (r.seated || 0) ? suit[(i + r.id) % suit.length] : ''}
                    </span>
                  ))}
                  <span style={{ marginLeft: 6, fontSize: 12, color: 'var(--cream-dim)' }}>
                    {r.seated}/{r.max_players} seated
                  </span>
                </div>
              </div>
              <button className="btn btn-primary" onClick={() => onPlay(r.id)}>
                Sit & Play
              </button>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 34, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
          <span className="gold-text">Low on chips?</span>
          <input className="field" type="number" min={1} value={addAmt} style={{ width: 150 }}
            onChange={(e) => setAddAmt(Number(e.target.value))} />
          <button className="btn" disabled={busy} onClick={addChips}>Add chips to bankroll</button>
        </div>
      </div>
    </div>
  );
}