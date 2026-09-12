import { useEffect, useState } from 'react';
import { api } from './api';
import Card from './components/Card';
import Seat from './components/Seat';

const STREET = { preflop: 'Pre-Flop', flop: 'Flop', turn: 'Turn', river: 'River' };
const POLL = 1200;

function fm(n) {
  return Number(n || 0).toLocaleString('en-US');
}

export default function Game({ user, roomId, onLeave, onBack }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [joined, setJoined] = useState(false);
  const [seatReq, setSeatReq] = useState(false);
  const [betSize, setBetSize] = useState(0);
  const [showRebuy, setShowRebuy] = useState(false);
  const [rebuyAmt, setRebuyAmt] = useState(1000);
  const [chatText, setChatText] = useState('');
  const [leaving, setLeaving] = useState(false);

  /* ---- join + poll ---- */
  const doJoin = async () => {
    try {
      const d = await api.join(roomId);
      setData(d);
      setJoined(true);
      return true;
    } catch (e) {
      setError(e.message);
      setSeatReq(true);
      return false;
    }
  };

  useEffect(() => {
    let alive = true;
    let timer = null;
    let joinedFlag = false;

    const loop = async () => {
      if (!alive) return;
      if (!joinedFlag) {
        joinedFlag = await doJoin();
        if (!joinedFlag) {
          setSeatReq(true);
          if (alive) timer = setTimeout(loop, 3500);
          return;
        }
        setSeatReq(false);
        if (!alive) return;
      }
      try {
        const d = await api.state(roomId);
        if (alive) {
          setData(d);
          setError(null);
        }
      } catch (e) {
        if (alive) setError(e.message);
      }
      if (alive) timer = setTimeout(loop, POLL);
    };

    loop();
    return () => {
      alive = false;
      if (timer) clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roomId]);

  /* ---- actions ---- */
  const act = async (action, amount) => {
    if (busy) return;
    setBusy(true);
    try {
      const d = await api.act(roomId, action, amount ? Number(amount) : undefined);
      setData(d);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const sendChat = async (ev) => {
    ev.preventDefault();
    const m = chatText.trim();
    if (!m) return;
    setChatText('');
    try {
      await api.chat(roomId, m);
      const d = await api.state(roomId);
      setData(d);
    } catch (e) {
      setError(e.message);
    }
  };

  const rebuy = async () => {
    try {
      const d = await api.tableBuyin(roomId, Math.max(1, rebuyAmt));
      setData(d);
      setShowRebuy(false);
    } catch (e) {
      setError(e.message);
    }
  };

  const leave = async () => {
    setLeaving(true);
    try {
      await api.leave(roomId);
    } catch { /* ignore */ }
    onBack();
  };

  /* ---- derived ---- */
  const g = data?.game;
  const phase = g?.phase || 'idle';
  const players = data?.players || [];
  const me = players.find((p) => p.is_me) || null;
  const bb = data?.room?.big_blind || 10;
  const toCall = me && g ? Math.max(0, g.current_bet - me.street_bet) : 0;
  const canCheck = toCall <= 0;

  const sliderMin = canCheck ? bb : (g?.current_bet || bb) + (g?.min_raise || bb);
  const sliderMax = me ? me.street_bet + me.stack : bb * 10;
  const pot = g?.pot || 0;

  const shownBet = Math.min(Math.max(betSize || sliderMin, sliderMin), Math.max(sliderMin, sliderMax));

  const waitName = players.find((p) => g && g.turn_seat != null && p.seat === g.turn_seat && !p.folded)?.username || '';

  const showdowns = players.filter((p) => p.result && p.result.won > 0);
  const topWinner = showdowns.reduce((a, b) => ((b.result.won || 0) > (a?.result.won || 0) ? b : a), null);

  const loading = !data;

  return (
    <div className="game">
      {/* ============ stage ============ */}
      <div className="table-stage">
        <div className="table-header">
          <span className="room-name">{data?.room?.name || ''}</span>
          <span className="th-blind">
            Blinds {data?.room?.small_blind}/{data?.room?.big_blind} · Buy-in {data?.room?.min_buyin}–
            {data?.room?.max_buyin}
          </span>
          <span className="th-hand">Hand #{g?.hand_id || 0}</span>
          <span className="chips-badge"><span className="chip xs" style={{ background: 'var(--gold)' }} /> {fm(user.chips)}</span>
          <button className="btn" onClick={() => setShowRebuy(true)}>Rebuy</button>
          <button className="btn" disabled={leaving} onClick={leave}>Leave</button>
        </div>

        <div className="table-scene">
          {loading ? <div className="spin" /> : (
            <div className="table">
              <div className="poker-table">
                <div className="felt-ellipse" />
                <div className="felt-veneer" />

                {/* pot */}
                <div className="pot-center">
                  <div className="pot-amount">${fm(pot)}</div>
                  <div className="pot-label">Pot</div>
                </div>

                {/* community */}
                <div className="community">
                  {[0, 1, 2, 3, 4].map((i) => (
                    <Card
                      key={i}
                      card={(g?.community || [])[i]}
                      flip
                    />
                  ))}
                </div>

                {/* winner banner */}
                {phase === 'showdown' && topWinner && (
                  <div className="winner-banner gold-shimmer">
                    {topWinner.username} wins ${fm(topWinner.result.won)}
                  </div>
                )}

                {/* showdown panel */}
                {phase === 'showdown' && showdowns.length > 0 && (
                  <div className="showdown-panel">
                    <div className="showdown-title">Showdown</div>
                    <div className="showdown-list">
                      {showdowns.map((p) => (
                        <div className="showdown-row" key={p.id}>
                          <b>{p.username}</b> — {p.result.label || '—'} · <b>+${fm(p.result.won)}</b>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {/* seats */}
                {players.map((p) => (
                  <Seat
                    key={p.seat}
                    player={p}
                    phase={phase}
                    turnSeat={g?.turn_seat ?? null}
                    dealerSeat={g?.dealer_seat ?? 0}
                    countdown={me && p.is_me ? g?.countdown : null}
                  />
                ))}

                {seatReq && (
                  <div className="pot-center" style={{ top: '78%' }}>
                    <div className="status-line">{error || 'Waiting for a free seat…'}</div>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        {/* ===== action bar ===== */}
        <ActionBar
          me={me}
          g={g}
          phase={phase}
          busy={busy}
          canCheck={canCheck}
          toCall={toCall}
          bb={bb}
          sliderMin={sliderMin}
          sliderMax={sliderMax}
          betSize={shownBet}
          setBetSize={setBetSize}
          pot={pot}
          act={act}
          waitName={waitName}
          error={error}
          seatReq={seatReq}
          onRebuy={() => setShowRebuy(true)}
        />
      </div>

      {/* ============ chat ============ */}
      <ChatPanel
        chat={data?.chat || []}
        mine={user.id}
        text={chatText}
        setText={setChatText}
        onSend={sendChat}
        players={players}
      />

      {showRebuy && (
        <div className="overlay" onClick={() => setShowRebuy(false)}>
          <div className="modal card-panel" onClick={(e) => e.stopPropagation()}>
            <div className="modal-title">Rebuy</div>
            <div className="modal-sub">Add chips to your stack at this table.</div>
            <div className="modal-row">
              <input className="field" type="number" min={1} value={rebuyAmt}
                onChange={(e) => setRebuyAmt(Number(e.target.value))} />
              <button className="btn btn-primary" onClick={rebuy}>Add</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function ActionBar({ me, g, phase, busy, canCheck, toCall, bb, sliderMin, sliderMax, betSize, setBetSize, pot, act, waitName, error, seatReq, onRebuy }) {
  const inBetting = ['preflop', 'flop', 'turn', 'river'].includes(phase);
  const acting = me && me.acting && inBetting;

  const quick = [
    { label: 'Min', v: sliderMin },
    { label: '+BB', v: Math.min(sliderMax, betSize + bb) },
    { label: '½ Pot', v: Math.min(sliderMax, Math.max(sliderMin, betSize + Math.floor(pot / 2))) },
    { label: 'Pot', v: Math.min(sliderMax, Math.max(sliderMin, betSize + pot)) },
    { label: 'MAX', v: sliderMax },
  ];

  return (
    <div className="action-bar">
      <div className="action-ab">
        <div className="action-bar-l">
          <div className="hole">
            {(me?.cards || []).map((c, i) => <Card key={i} card={c} flip />)}
            {(!me || !me.cards || me.cards.length === 0) && (
              <div className="status-line" style={{ margin: 0, alignSelf: 'center', letterSpacing: 0.08 }}>
                {seatReq ? 'Sitting…' : me && phase === 'showdown' ? 'Hand over' : me ? '—' : '—'}
              </div>
            )}
          </div>

          <div className="ab-buttons">
            <button className="ab-btn ab-fold" disabled={!acting || busy} onClick={() => act('fold')}>FOLD</button>

            {canCheck ? (
              <button className="ab-btn ab-check" disabled={!acting || busy} onClick={() => act('check')}>CHECK</button>
            ) : (
              <button className="ab-btn ab-call" disabled={!acting || busy} onClick={() => act('call')}>
                CALL <b>${fm(Math.min(toCall, me?.stack || 0))}</b>
              </button>
            )}

            {inBetting && (
              <div className="ab-bet card-panel" style={{ padding: '10px 12px', borderColor: 'var(--border-gold)' }}>
                <label>{canCheck ? 'BET' : 'RAISE TO'}</label>
                <div className="bet-amt">${fm(betSize)}</div>
                <input
                  type="range"
                  min={sliderMin}
                  max={Math.max(sliderMin, sliderMax)}
                  value={betSize}
                  disabled={!acting || busy}
                  onChange={(e) => setBetSize(Number(e.target.value))}
                />
                <div style={{ display: 'flex', gap: 5, justifyContent: 'space-between', flexWrap: 'wrap' }}>
                  {quick.map((q) => (
                    <button key={q.label} className="btn" style={{ padding: '5px 9px', fontSize: 10 }}
                      disabled={!acting || busy} onClick={() => setBetSize(q.v)}>
                      {q.label}
                    </button>
                  ))}
                </div>
                <button className="ab-btn ab-raise" disabled={!acting || busy} onClick={() => act(canCheck ? 'bet' : 'raise', betSize)}>
                  {canCheck ? 'BET' : 'RAISE'} <b>${fm(betSize - (me?.street_bet || 0))}</b>
                </button>
              </div>
            )}

            <button className="ab-btn ab-raise" disabled={!acting || busy} onClick={() => act('allin')} style={{ minWidth: 88 }}>
              ALL-IN <b>${fm(me?.stack || 0)}</b>
            </button>
          </div>
        </div>
      </div>

      <div className="turn-note">
        {acting ? `Your move — ${Math.max(0, Math.ceil(g?.countdown ?? 0))}s` : ''}
        {!acting && inBetting && (me ? `Waiting for ${waitName || '…'}` : 'Sit at the table to play')}
        {!inBetting && phase === 'showdown' && 'Showdown'}
        {!inBetting && phase === 'idle' && 'Waiting for players…'}
      </div>
      {error && <div className="turn-note" style={{ color: 'var(--bad)' }}>{error}</div>}
    </div>
  );
}

function ChatPanel({ chat, mine, text, setText, onSend, players }) {
  return (
    <div className="side">
      <div className="side-head">RING CHAT</div>
      <div className="chat-messages">
        {chat.map((m, i) => (
          <div key={i} className="chat-row">
            <span className="who">{m.username}</span>
            <span className="msg">{m.message}</span>
          </div>
        ))}
        {chat.length === 0 && <span style={{ color: 'var(--cream-dim)', fontSize: 12 }}>No messages yet.</span>}
      </div>
      <form className="chat-form" onSubmit={onSend}>
        <input className="field" placeholder="Say something…" value={text} onChange={(e) => setText(e.target.value)} maxLength={200} />
        <button className="btn" type="submit">Send</button>
      </form>
    </div>
  );
}