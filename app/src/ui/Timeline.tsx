import { STATUS_LABEL } from '../domain/status';
import type { ShipmentEvent } from '../domain/types';
import { formatDateTime } from '../lib/format';
import { formatMoney } from '../lib/format';

const FAIL_LABEL: Record<string, string> = {
  not_home: 'niemand aanwezig',
  closed: 'adres gesloten',
  wrong_address: 'verkeerd adres',
  refused: 'geweigerd',
  damaged: 'beschadigd',
  not_ready: 'zending niet klaar',
  other: 'andere reden',
};

function describe(e: ShipmentEvent): string {
  const p = e.payload as Record<string, string | number | undefined>;
  switch (e.type) {
    case 'status':
      if (e.to_status === 'failed') return `Mislukt: ${FAIL_LABEL[String(p.reason)] ?? p.reason ?? ''}`;
      if (e.to_status === 'cancelled') return `Geannuleerd${p.reason ? `: ${p.reason}` : ''}`;
      return e.to_status ? STATUS_LABEL[e.to_status] : 'Status gewijzigd';
    case 'assignment':
      return `Toegewezen aan ${p.courier_name ?? 'koerier'}${p.stop_sequence ? ` (stop ${p.stop_sequence})` : ''}`;
    case 'pod':
      return `Bewijs van levering${p.receiver_name ? ` · ontvangen door ${p.receiver_name}` : ''}`;
    case 'price':
      return `Prijs aangepast van ${formatMoney(p.from_cents as number)} naar ${formatMoney(p.to_cents as number)} · ${p.reason ?? ''}`;
    case 'note':
      return String(p.note ?? '');
    case 'exception':
      return `Uitzondering: ${p.reason ?? ''}`;
    default:
      return e.type;
  }
}

export function Timeline({ events }: { events: ShipmentEvent[] }) {
  if (events.length === 0) return <p className="cv-muted">Nog geen gebeurtenissen.</p>;
  return (
    <ol className="cv-timeline">
      {[...events].reverse().map((e) => (
        <li key={e.id} className={`cv-timeline__item cv-timeline__item--${e.type}`}>
          <span className="cv-timeline__dot" />
          <div>
            <div className="cv-timeline__text">
              {describe(e)}
              {e.type === 'status' && e.payload.note ? <span className="cv-muted"> · {String(e.payload.note)}</span> : null}
            </div>
            <div className="cv-timeline__meta">
              {e.actor_name} · {formatDateTime(e.created_at)}
              {e.type === 'note' && e.payload.visible_to_customer ? ' · zichtbaar voor klant' : ''}
            </div>
          </div>
        </li>
      ))}
    </ol>
  );
}
