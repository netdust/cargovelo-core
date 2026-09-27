import { ArrowRight } from 'lucide-react';
import type { Address } from '../domain/types';
import { addressLine } from '../lib/format';

export function RouteCard({ pickup, delivery, compact = false }: { pickup: Address; delivery: Address; compact?: boolean }) {
  const side = (a: Address, right: boolean) => (
    <div className={right ? 'cv-route--right' : ''}>
      <div className="cv-route__city">{a.city}</div>
      <div className="cv-route__line">{a.company ? `${a.company} · ` : ''}{a.name}</div>
      {!compact && <div className="cv-route__line">{addressLine(a)}</div>}
      {!compact && a.phone && <div className="cv-route__line">{a.phone}</div>}
      {!compact && a.instructions && <div className="cv-route__line">“{a.instructions}”</div>}
    </div>
  );
  return (
    <div className="cv-route">
      {side(pickup, false)}
      <div className="cv-route__arrow"><ArrowRight size={18} /></div>
      {side(delivery, true)}
    </div>
  );
}
