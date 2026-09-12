const DENOMS = [
  { v: 5000, cls: 'chip-5000' },
  { v: 1000, cls: 'chip-1000' },
  { v: 500, cls: 'chip-500' },
  { v: 100, cls: 'chip-100' },
  { v: 25, cls: 'chip-25' },
];

export function chipClass(value) {
  for (const d of DENOMS) {
    if (value >= d.v) return d.cls;
  }
  return 'chip-25';
}

export default function ChipStack({ amount, size }) {
  if (!amount) return <span style={{ height: size === 'lg' ? 34 : 26, display: 'inline-block' }} />;

  const cls = chipClass(amount);
  const n = amount >= 5000 ? 3 : amount >= 1000 ? 2 : 1;
  const sizeCls = size === 'lg' ? 'chip-lg' : size === 'sm' ? 'chip-sm' : size === 'xs' ? 'chip-xs' : '';
  const w = size === 'lg' ? 44 : size === 'sm' ? 26 : 32;

  return (
    <span className="chip-stack" style={{ position: 'relative', width: w + 6, height: size === 'lg' ? 44 : 32 }}>
      {Array.from({ length: n }).map((_, k) => (
        <span key={k} className={`chip ${cls} ${sizeCls}`} style={{ position: 'absolute', left: 1 + k * 2.5, top: k * 2 }}>
          <span className="chip-inner" />
          <span className="chip-edge" />
        </span>
      ))}
    </span>
  );
}