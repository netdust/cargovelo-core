import { useState } from 'react';
import { Button, Checkbox, Input, Modal, Select, Tabs, Textarea } from '@sakaniui/react';
import type { Shipment, ShipmentStatus } from '../domain/types';
import { CHANNEL_LABEL, FAIL_REASONS, SERVICE_LABEL, STATUS_LABEL, WEIGHT_LABEL, canTransition, isOpen } from '../domain/status';
import { useApi, useAppContext } from '../lib/api-context';
import { useToast } from '../lib/toast';
import { errorMessage } from '../lib/useLoad';
import { formatDateTime, formatMoney, formatWindow } from '../lib/format';
import { useLoad } from '../lib/useLoad';
import { StatusBadge } from '../ui/StatusBadge';
import { RouteCard } from '../ui/RouteCard';
import { Timeline } from '../ui/Timeline';
import { Inline, KeyValue, Section, Stack } from '../ui/Page';
import { PodView } from '../ui/PodView';

interface Props {
  shipment: Shipment;
  onChange: (s: Shipment) => void;
}

/** Full detail + every dispatcher action. Server decides; buttons only reflect TRANSITIONS. */
export function ShipmentDetail({ shipment: s, onChange }: Props) {
  const api = useApi();
  const ctx = useAppContext();
  const toast = useToast();
  const [tab, setTab] = useState('details');
  const [busy, setBusy] = useState<string | null>(null);
  const [modal, setModal] = useState<'cancel' | 'fail' | 'price' | 'deliver' | null>(null);
  const [reason, setReason] = useState('');
  const [failReason, setFailReason] = useState('not_home');
  const [price, setPrice] = useState(((s.price_cents ?? 0) / 100).toFixed(2));
  const [priceReason, setPriceReason] = useState('');
  const [note, setNote] = useState('');
  const [noteVisible, setNoteVisible] = useState(false);
  const [receiver, setReceiver] = useState('');
  const couriers = useLoad(() => api.couriers(), []);

  const run = async (key: string, fn: () => Promise<Shipment>, ok: string) => {
    setBusy(key);
    try {
      onChange(await fn());
      toast.success(ok);
      setModal(null);
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(null);
    }
  };
  const status = (to: ShipmentStatus, payload?: Record<string, string>) => run(to, () => api.opsStatus(s.id, to, payload), `Status: ${STATUS_LABEL[to]}`);

  const can = (to: ShipmentStatus) => canTransition(s.status, to);
  const parcels = s.parcels.map((p) => `${p.count}× ${WEIGHT_LABEL[p.weight_class] ?? p.weight_class}${p.fragile ? ' · breekbaar' : ''}${p.cooled ? ' · gekoeld' : ''}`).join(', ');

  return (
    <Stack gap={20}>
      <Inline justify="space-between">
        <Inline>
          <StatusBadge status={s.status} solid />
          {s.exception && <span style={{ color: 'var(--color-danger-fg)' }}>Uitzondering: {FAIL_REASONS.find((r) => r.value === s.exception)?.label ?? s.exception}</span>}
        </Inline>
        <span className="cv-strong" style={{ fontSize: 18 }}>{formatMoney(s.price_cents)}</span>
      </Inline>

      <div className="cv-panel"><RouteCard pickup={s.pickup} delivery={s.delivery} /></div>

      {isOpen(s.status) && (
        <div className="cv-panel" style={{ background: 'var(--color-bg-subtle)' }}>
          <div className="cv-strong" style={{ marginBottom: 8 }}>Acties</div>
          <Inline>
            {can('confirmed') && s.status === 'requested' && <Button size="sm" loading={busy === 'confirmed'} onClick={() => status('confirmed')}>Bevestigen</Button>}
            {can('confirmed') && s.status === 'failed' && <Button size="sm" loading={busy === 'confirmed'} onClick={() => status('confirmed')}>Opnieuw inplannen</Button>}
            {(s.status === 'confirmed' || s.status === 'assigned') && (
              <Select size="sm" placeholder="Wijs toe aan koerier…" value={s.courier_id ? String(s.courier_id) : ''} onChange={(v) => run('assign', () => api.opsAssign(s.id, v ? Number(v) : null), v ? 'Toegewezen' : 'Losgekoppeld')}
                options={[{ value: '', label: s.courier_id ? '— Loskoppelen' : 'Kies koerier' }, ...(couriers.data ?? []).filter((c) => c.active).map((c) => ({ value: String(c.id), label: `${c.name} · ${c.hub}` }))]} />
            )}
            {can('picked_up') && <Button size="sm" variant="secondary" loading={busy === 'picked_up'} onClick={() => status('picked_up')}>Opgehaald</Button>}
            {can('in_transit') && <Button size="sm" variant="secondary" loading={busy === 'in_transit'} onClick={() => status('in_transit')}>Onderweg</Button>}
            {can('delivered') && <Button size="sm" variant="secondary" onClick={() => setModal('deliver')}>Geleverd</Button>}
            {can('failed') && <Button size="sm" variant="outline" onClick={() => setModal('fail')}>Mislukt</Button>}
            {can('cancelled') && <Button size="sm" variant="ghost" onClick={() => setModal('cancel')}>Annuleren</Button>}
            {ctx.user.role !== 'courier' && <Button size="sm" variant="ghost" onClick={() => setModal('price')}>Prijs aanpassen</Button>}
          </Inline>
        </div>
      )}

      <Tabs items={[{ value: 'details', label: 'Details' }, { value: 'timeline', label: `Tijdlijn (${s.events?.length ?? 0})` }, { value: 'pod', label: 'Bewijs' }]} value={tab} onChange={setTab} />

      {tab === 'details' && (
        <Section>
          <KeyValue rows={[
            ['Klant', <span>{s.customer_name}{s.contact_email ? <span className="cv-muted"> · {s.contact_email}</span> : null}</span>],
            ['Dienst', `${SERVICE_LABEL[s.service]} · hub ${s.hub}`],
            ['Ophaling', formatWindow(s.pickup_window)],
            ['Levering', s.delivery_window.end ? `uiterlijk ${formatDateTime(s.delivery_window.end)}` : '—'],
            ['Pakketten', parcels],
            ['Koerier', s.courier_name ? `${s.courier_name}${s.stop_sequence ? ` · stop ${s.stop_sequence}` : ''}` : '—'],
            ['Prijs', <span>{formatMoney(s.price_cents)}{s.price_override_reason ? <span className="cv-muted"> · aangepast: {s.price_override_reason}</span> : null}</span>],
            ['Kanaal', CHANNEL_LABEL[s.channel]],
            ['Opmerkingen', s.remarks || '—'],
            ['Aangemaakt', formatDateTime(s.created_at)],
            ['Track & trace', <a href={`?view=tracking&t=${s.tracking_token}`} target="_blank" rel="noreferrer" className="cv-mono">{s.tracking_token.slice(0, 8)}…</a>],
          ]} />
        </Section>
      )}

      {tab === 'timeline' && (
        <Stack gap={16}>
          <Timeline events={s.events ?? []} />
          <div className="cv-panel">
            <Textarea label="Notitie toevoegen" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
            <Inline justify="space-between">
              <Checkbox label="Zichtbaar voor klant" checked={noteVisible} onChange={(e) => setNoteVisible(e.target.checked)} />
              <Button size="sm" variant="secondary" disabled={!note.trim()} loading={busy === 'note'} onClick={() => run('note', () => api.opsNote(s.id, note, noteVisible), 'Notitie toegevoegd').then(() => setNote(''))}>Toevoegen</Button>
            </Inline>
          </div>
        </Stack>
      )}

      {tab === 'pod' && <PodView shipment={s} scope="ops" />}

      <Modal open={modal === 'cancel'} onClose={() => setModal(null)} title="Zending annuleren?" variant="destructive" description="De klant krijgt een annulatiemail. Dit kan niet ongedaan gemaakt worden." confirmLabel="Annuleren" cancelLabel="Terug" confirmLoading={busy === 'cancelled'} onConfirm={() => status('cancelled', { reason })}>
        <Input label="Reden (optioneel)" value={reason} onChange={(e) => setReason(e.target.value)} />
      </Modal>
      <Modal open={modal === 'fail'} onClose={() => setModal(null)} title="Levering mislukt" description="De zending krijgt een uitzondering en kan opnieuw ingepland worden." confirmLabel="Registreren" confirmLoading={busy === 'failed'} onConfirm={() => status('failed', { reason: failReason, note })}>
        <Stack>
          <Select label="Reden" value={failReason} onChange={setFailReason} options={FAIL_REASONS} />
          <Input label="Toelichting" value={note} onChange={(e) => setNote(e.target.value)} />
        </Stack>
      </Modal>
      <Modal open={modal === 'deliver'} onClose={() => setModal(null)} title="Als geleverd markeren" description="Normaal doet de koerier dit in de app, met foto of handtekening." confirmLabel="Geleverd" confirmLoading={busy === 'delivered'} onConfirm={() => status('delivered', { receiver_name: receiver, note })}>
        <Stack>
          <Input label="Ontvangen door" value={receiver} onChange={(e) => setReceiver(e.target.value)} />
          <Input label="Notitie" value={note} onChange={(e) => setNote(e.target.value)} />
        </Stack>
      </Modal>
      <Modal open={modal === 'price'} onClose={() => setModal(null)} title="Prijs aanpassen" description={`Berekende prijs: ${formatMoney(s.price_cents)}. Een aangepaste prijs wordt niet meer herberekend.`} confirmLabel="Opslaan" confirmLoading={busy === 'price'} onConfirm={() => run('price', () => api.opsPrice(s.id, Math.round(parseFloat(price.replace(',', '.')) * 100), priceReason), 'Prijs aangepast')}>
        <Stack>
          <Input label="Nieuwe prijs (EUR, excl. btw)" inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value)} />
          <Input label="Reden" required value={priceReason} onChange={(e) => setPriceReason(e.target.value)} placeholder="Wachttijd 20 min, extra stop, …" />
        </Stack>
      </Modal>
    </Stack>
  );
}
