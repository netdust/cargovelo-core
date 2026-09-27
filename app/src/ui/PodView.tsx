import type { ReactNode } from 'react';
import type { Shipment } from '../domain/types';
import { useApi } from '../lib/api-context';
import { formatDateTime } from '../lib/format';
import { KeyValue } from './Page';

export function PodView({ shipment: s, scope }: { shipment: Shipment; scope: 'ops' | 'portal' }) {
  const api = useApi();
  const pod = [...(s.events ?? [])].reverse().find((e) => e.type === 'pod');
  if (!pod) return <p className="cv-muted">{s.status === 'delivered' ? 'Geen bewijs geregistreerd.' : 'Nog niet geleverd.'}</p>;
  const p = pod.payload as Record<string, string | number | undefined>;
  const photo = p.photo_path ? api.podUrl(scope, s.id, 'photo') : '';
  const signature = p.signature_path || p.signature_data ? api.podUrl(scope, s.id, 'signature') : '';
  return (
    <div style={{ display: 'grid', gap: 12 }}>
      <KeyValue rows={[
        ['Geleverd op', formatDateTime(pod.created_at)],
        ['Door', pod.actor_name],
        ['Ontvangen door', p.receiver_name || '—'],
        ['Notitie', p.note || '—'],
        ...(p.lat ? [['Locatie', <a href={`https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=18/${p.lat}/${p.lng}`} target="_blank" rel="noreferrer">{Number(p.lat).toFixed(5)}, {Number(p.lng).toFixed(5)}</a>] as [string, ReactNode]] : []),
      ]} />
      {photo && <img src={photo} alt="Foto bij levering" className="cv-photo-preview" />}
      {signature && <img src={signature} alt="Handtekening" className="cv-photo-preview" style={{ background: '#fff', maxHeight: 160 }} />}
    </div>
  );
}
