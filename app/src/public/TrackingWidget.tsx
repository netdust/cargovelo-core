import { useState } from 'react';
import { Bike } from 'lucide-react';
import { Alert, Button, EmptyState, Skeleton, Textarea } from '@sakaniui/react';
import { useApi } from '../lib/api-context';
import { useLoad, errorMessage } from '../lib/useLoad';
import { STATUS_LABEL } from '../domain/status';
import { formatDateTime, formatWindow } from '../lib/format';
import { StatusBadge } from '../ui/StatusBadge';
import { Stack } from '../ui/Page';

const STEPS = ['confirmed', 'picked_up', 'in_transit', 'delivered'] as const;
const STEP_LABEL: Record<string, string> = { confirmed: 'Ingepland', picked_up: 'Opgehaald', in_transit: 'Onderweg', delivered: 'Geleverd' };

/** [cargovelo_tracking]: public status page for the recipient, token in ?t=. */
export function TrackingWidget({ token }: { token: string }) {
  const api = useApi();
  const view = useLoad(() => (token ? api.tracking(token) : Promise.reject(new Error('Geen zending opgegeven.'))), [token]);
  const [instr, setInstr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const v = view.data;

  const save = async () => {
    if (instr === null) return;
    setBusy(true);
    setMsg(null);
    try {
      view.setData(await api.trackingInstructions(token, instr));
      setMsg('Instructies doorgegeven aan de koerier.');
      setInstr(null);
    } catch (e) {
      setMsg(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  if (view.error) return <div className="cv-root cv-track" style={{ padding: 16 }}><EmptyState type="no-results" title="Zending niet gevonden" description="Controleer de link uit je e-mail of sms." /></div>;
  if (!v) return <div className="cv-root cv-track" style={{ padding: 16 }}><Skeleton variant="rect" height={240} /></div>;

  const reached = (step: string) => {
    if (v.status === 'failed' || v.status === 'cancelled') return v.events.some((e) => e.to_status === step);
    const order = ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit', 'delivered'];
    const map: Record<string, string> = { confirmed: 'confirmed', picked_up: 'picked_up', in_transit: 'in_transit', delivered: 'delivered' };
    return order.indexOf(v.status) >= order.indexOf(map[step]!) || (step === 'confirmed' && v.status === 'assigned');
  };

  return (
    <div className="cv-root cv-track" style={{ padding: 16 }}>
      <div className="cv-panel">
        <Stack gap={12}>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}><Bike /> <span className="cv-mono cv-muted">{v.reference}</span></div>
          <div className="cv-track__status">
            <div className="cv-track__big">{v.status === 'in_transit' ? 'Onze fietskoerier is onderweg' : v.status === 'delivered' ? 'Geleverd' : v.status === 'failed' ? 'Levering niet gelukt' : v.status === 'cancelled' ? 'Geannuleerd' : v.status === 'requested' ? 'Aanvraag ontvangen' : 'Ingepland'}</div>
            <div className="cv-muted">Levering in {v.delivery_city}{v.delivery_window.end && v.status !== 'delivered' ? ` · verwacht voor ${formatWindow({ start: null, end: v.delivery_window.end })}` : ''}</div>
          </div>
          <div className="cv-steps" aria-hidden="true">
            {STEPS.map((s) => <div key={s} className={`cv-steps__step ${reached(s) ? (v.status === 'failed' && s === 'delivered' ? 'cv-steps__step--failed' : 'cv-steps__step--done') : ''}`} />)}
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between' }} className="cv-subtle">{STEPS.map((s) => <span key={s}>{STEP_LABEL[s]}</span>)}</div>
          {v.status === 'failed' && <Alert color="warning" title="De koerier kon niet leveren" description="Dispatch plant een nieuwe poging in. Pas hieronder je instructies aan als dat helpt." />}
          {v.pod && <Alert color="success" title={`Geleverd op ${formatDateTime(v.pod.delivered_at)}`} description={v.pod.receiver_name ? `Ontvangen door ${v.pod.receiver_name}` : undefined} />}
        </Stack>
      </div>

      {v.can_edit_instructions && (
        <div className="cv-panel">
          <Stack gap={8}>
            <div className="cv-strong">Instructies voor de koerier</div>
            <p className="cv-muted">Niet thuis? Zeg waar het pakket mag, of bij welke buur.</p>
            <Textarea rows={2} value={instr ?? v.instructions} onChange={(e) => setInstr(e.target.value)} placeholder="bv. Bij de buren op nr. 12, of achteraan in de tuin" />
            <div><Button size="sm" disabled={instr === null || instr === v.instructions} loading={busy} onClick={save}>Doorgeven</Button></div>
            {msg && <p className="cv-subtle">{msg}</p>}
          </Stack>
        </div>
      )}

      <div className="cv-panel">
        <div className="cv-strong" style={{ marginBottom: 8 }}>Verloop</div>
        <ol className="cv-timeline">
          {[...v.events].reverse().map((e, i) => (
            <li key={i} className={`cv-timeline__item ${e.type === 'status' ? 'cv-timeline__item--status' : ''}`}>
              <span className="cv-timeline__dot" />
              <div>
                <div className="cv-timeline__text">{e.type === 'status' && e.to_status ? <StatusBadge status={e.to_status} /> : e.note}</div>
                <div className="cv-timeline__meta">{formatDateTime(e.created_at)}{e.type === 'status' && e.to_status ? ` · ${STATUS_LABEL[e.to_status]}` : ''}</div>
              </div>
            </li>
          ))}
        </ol>
      </div>
    </div>
  );
}
