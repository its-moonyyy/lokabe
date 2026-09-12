import Card from './Card';
import ChipStack from './Chip';

export const SEAT_POS = [
  { x: 50, y: 88 },
  { x: 86, y: 74 },
  { x: 92, y: 42 },
  { x: 70, y: 15 },
  { x: 30, y: 15 },
  { x: 8, y: 42 },
];

export default function Seat({ player, phase, turnSeat, dealerSeat, countdown }) {
  if (!player) return null;
  const pos = SEAT_POS[player.seat] || SEAT_POS[0];
  const myTurn = !!player.acting;
  const inBetting = ['preflop', 'flop', 'turn', 'river'].includes(phase);
  const isTurn = turnSeat === player.seat && inBetting && !player.folded;
  const showCards = player.cards && player.cards.length > 0;
  const revealedAtShowdown = player.is_me ? [] : showCards ? player.cards : [];

  return (
    <div
      className={['seat', isTurn || myTurn ? 'turn' : '', player.folded ? 'folded' : ''].filter(Boolean).join(' ')}
      style={{ left: `${pos.x}%`, top: `${pos.y}%` }}
    >
      {myTurn && countdown != null && (
        <div className="seat-countdown" style={{ ['--p']: 100 - (countdown / 40) * 100 }}>
          <span>{Math.max(0, Math.ceil(countdown))}</span>
        </div>
      )}

      <div className="seat-box">
        {dealerSeat === player.seat && <div className="seat-dealer">D</div>}
        <div className={`seat-avatar ${player.is_bot ? 'bot' : ''}`}>{player.avatar || '♠'}</div>
        <div className="seat-name">
          {player.username}
          {player.is_me && <span style={{ color: 'var(--gold)', fontFamily: 'Cinzel', fontSize: 9, letterSpacing: '0.25em', marginLeft: 4 }}>YOU</span>}
        </div>
        {player.stack > 0 && <div className="seat-stack">${fm(player.stack)}</div>}

        <div className="seat-cards" style={{ display: 'flex', gap: 4, justifyContent: 'center', minHeight: 24, marginTop: 3 }}>
          {showCards && player.is_me
            ? player.cards.map((c, i) => <Card key={i} card={c} size="small" />)
            : player.folded
              ? <span className="seat-badge folded">FOLDED</span>
              : null}
        </div>

        {player.all_in && <div className="seat-badge allin">ALL-IN</div>}

        {player.result && player.result.won > 0 && (
          <div style={{ marginTop: 4 }}>
            <div className="seat-winner" />
            <div className="gold-shimmer" style={{ fontSize: 12, fontFamily: 'Playfair Display', fontWeight: 700 }}>
              +${fm(player.result.won)}
            </div>
          </div>
        )}
      </div>

      {player.street_bet > 0 && (
        <div className="seat-bet">
          <ChipStack amount={player.street_bet} size="sm" />
          <span className="bet-amt">${fm(player.street_bet)}</span>
        </div>
      )}

      {revealedAtShowdown.length > 0 && (
        <div className="seat-cards" style={{ display: 'flex', gap: 3, marginTop: 4 }}>
          {revealedAtShowdown.map((c, i) => <Card key={i} card={c} size="small" />)}
        </div>
      )}
    </div>
  );
}

function fm(n) {
  return Number(n || 0).toLocaleString('en-US');
}