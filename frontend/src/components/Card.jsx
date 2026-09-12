const SUITS = { s: '♠', h: '♥', d: '♦', c: '♣' };

export function cardParts(card) {
  const rank = card[0] === 'T' ? '10' : card[0];
  const suit = SUITS[card[1]] || '?';
  return { rank, suit, red: card[1] === 'h' || card[1] === 'd' };
}

export default function Card({ card, size, flip }) {
  if (!card) {
    return <div className={`card card-back ${size === 'small' ? 'card-small' : ''}`} />;
  }
  const { rank, suit, red } = cardParts(card);
  const cls = ['card', red ? 'red' : '', size === 'small' ? 'card-small' : '', flip ? 'flip' : ''].filter(Boolean).join(' ');
  return (
    <div className={cls}>
      <span className="c-rank">{rank}</span>
      <span className="c-suit-top">{suit}</span>
      <span className="c-big">{suit}</span>
      <span className="c-suit-corner">{suit}</span>
    </div>
  );
}