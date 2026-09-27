import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Camera, Navigation, Phone, RefreshCw } from 'lucide-react';
import { Alert, Badge, Button, Input, Select, Skeleton, TopBarMobile } from '@sakaniui/react';
import type { Shipment, ShipmentStatus } from '../domain/types';
import { FAIL_REASONS, SERVICE_LABEL, STATUS_LABEL, isOpen } from '../domain/status';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad, errorMessage } from '../lib/useLoad';
import { useToast } from '../lib/toast';
import { formatTime, addressLine } from '../lib/format';
import { StatusBadge } from '../ui/StatusBadge';
import { Stack, Inline } from '../ui/Page';
import type { StatusPayload } from '../api/client';

interface Queued {
  id: number;
  status: ShipmentStatus;
  payload: StatusPayload;
  at: string;
}
const QUEUE_KEY = 'cargovelo.courier.queue';
const readQueue = (): Queued[] => {
  try {
    return JSON.parse(localStorage.getItem(QUEUE_KEY) ?? '[]');
  } catch {
    return [];
  }
};
const writeQueue = (q: Queued[]) => {
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
  } catch {
    /* private mode */
  }
};

/**
 * Mobile-first courier app: today's stops in order, one-tap status, proof of delivery with
 * photo and signature. Status changes made offline queue in localStorage and sync on reconnect.
 */
export function CourierApp() {
  const api = useApi();
  const ctx = useAppContext();
  const toast = useToast();
  const stops = useLoad(() => api.courierStops(), []);
  const [openId, setOpenId] = useState<number | null>(null);
  const [online, setOnline] = useState(navigator.onLine);
  const [queue, setQueue] = useState<Queued[]>(readQueue());

  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  // Flush queued actions when back online.
  useEffect(() => {
    if (!online || queue.length === 0) return;
    (async () => {
      const rest: Queued[] = [];
      for (const q of queue) {
        try {
          await api.courierStatus(q.id, q.status, q.payload);
        } catch (e) {
          if ((e as { status?: number }).status === 0) rest.push(q);
          else toast.error(`Stop ${q.id} niet gesynchroniseerd`, errorMessage(e));
        }
      }
      setQueue(rest);
      writeQueue(rest);
      if (rest.length < queue.length) {
        toast.success('Offline wijzigingen gesynchroniseerd');
        stops.reload();
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online]);

  const setStatus = async (s: Shipment, status: ShipmentStatus, payload: StatusPayload = {}): Promise<boolean> => {
    try {
      const updated = await api.courierStatus(s.id, status, payload);
      stops.setData((prev) => (prev ? prev.map((x) => (x.id === updated.id ? { ...x, ...updated } : x)) : prev));
      toast.success(STATUS_LABEL[status]);
      return true;
    } catch (e) {
      if ((e as { status?: number }).status === 0) {
        const q = [...queue, { id: s.id, status, payload, at: new Date().toISOString() }];
        setQueue(q);
        writeQueue(q);
        stops.setData((prev) => (prev ? prev.map((x) => (x.id === s.id ? { ...x, status, exception: payload.reason ?? x.exception } : x)) : prev));
        toast.toast('info', 'Offline opgeslagen', 'Wordt verstuurd zodra er verbinding is.');
        return true;
      }
      toast.error('Niet gelukt', errorMessage(e));
      return false;
    }
  };

  const current = stops.data?.find((s) => s.id === openId) ?? null;
  const open = (stops.data ?? []).filter((s) => isOpen(s.status));
  const done = (stops.data ?? []).filter((s) => !isOpen(s.status));

  if (ctx.user.courier_id === null) {
    return <div className="cv-root" style={{ padding: 24 }}><Alert color="warning" title="Geen koeriersprofiel" description="Je account is nog niet aan een koerier gekoppeld. Vraag dispatch om dat te doen." /></div>;
  }

  return (
    <div className="cv-root cv-courier">
      {!online && <div className="cv-offline">Offline · {queue.length} wijziging(en) wachten op verbinding</div>}
      {current ? (
        <StopDetail stop={current} onBack={() => setOpenId(null)} onStatus={(st, p) => setStatus(current, st, p)} />
      ) : (
        <>
          <TopBarMobile type="title" title={`Mijn stops · ${new Date().toLocaleDateString('nl-BE', { weekday: 'long', day: 'numeric', month: 'long' })}`} onMenu={() => (window.location.href = ctx.urls.logout)} trailing={<Button variant="ghost" size="sm" onClick={stops.reload} aria-label="Vernieuwen"><RefreshCw size={16} /></Button>} />
          {stops.loading && !stops.data ? <div style={{ padding: 16 }}><Skeleton variant="rect" height={300} /></div> : (
            <>
              {open.length === 0 && <div style={{ padding: 24, textAlign: 'center' }} className="cv-muted">Geen open stops. Goed gereden!</div>}
              {open.map((s, i) => <StopRow key={s.id} stop={s} index={i + 1} onClick={() => setOpenId(s.id)} />)}
              {done.length > 0 && <div className="cv-subtle" style={{ padding: '16px 16px 4px', textTransform: 'uppercase' }}>Afgewerkt ({done.length})</div>}
              {done.map((s) => <StopRow key={s.id} stop={s} onClick={() => setOpenId(s.id)} />)}
            </>
          )}
        </>
      )}
    </div>
  );
}

function StopRow({ stop: s, index, onClick }: { stop: Shipment; index?: number; onClick: () => void }) {
  const next = s.status === 'assigned' || s.status === 'confirmed' ? s.pickup : s.delivery;
  const verb = s.status === 'assigned' || s.status === 'confirmed' ? 'Ophalen' : 'Leveren';
  return (
    <div className={`cv-stop ${isOpen(s.status) ? '' : 'cv-stop--done'}`} onClick={onClick} role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && onClick()}>
      <div className="cv-stop__head">
        <Inline gap={10}>{index && <span className="cv-stop__seq">{index}</span>}<span className="cv-strong">{verb} · {next.company || next.name}</span></Inline>
        <StatusBadge status={s.status} />
      </div>
      <div className="cv-muted">{addressLine(next)}</div>
      <Inline gap={6}>
        <Badge variant={s.service === 'express' ? 'warning' : 'neutral'}>{SERVICE_LABEL[s.service]}</Badge>
        <span className="cv-subtle">{s.pickup_window.start ? formatTime(s.pickup_window.start) : 'ASAP'} → {formatTime(s.delivery_window.end)}</span>
        {s.parcels.some((p) => p.cooled) && <Badge variant="info">Gekoeld</Badge>}
        {s.parcels.some((p) => p.fragile) && <Badge variant="warning">Breekbaar</Badge>}
      </Inline>
    </div>
  );
}

function StopDetail({ stop: s, onBack, onStatus }: { stop: Shipment; onBack: () => void; onStatus: (st: ShipmentStatus, p?: StatusPayload) => Promise<boolean> }) {
  const [mode, setMode] = useState<'view' | 'deliver' | 'fail'>('view');
  const [busy, setBusy] = useState(false);
  const [receiver, setReceiver] = useState('');
  const [note, setNote] = useState('');
  const [reason, setReason] = useState('not_home');
  const [photo, setPhoto] = useState<string | null>(null);
  const sigRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const [signed, setSigned] = useState(false);

  const act = async (st: ShipmentStatus, p?: StatusPayload) => {
    setBusy(true);
    const ok = await onStatus(st, p);
    setBusy(false);
    if (ok) {
      setMode('view');
      if (st === 'delivered' || st === 'failed') onBack();
    }
  };

  const onPhoto = (file: File | undefined) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = () => {
        const max = 1280;
        const scale = Math.min(1, max / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * scale);
        c.height = Math.round(img.height * scale);
        c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
        setPhoto(c.toDataURL('image/jpeg', 0.8));
      };
      img.src = String(reader.result);
    };
    reader.readAsDataURL(file);
  };

  const pos = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    return [((e.clientX - r.left) / r.width) * e.currentTarget.width, ((e.clientY - r.top) / r.height) * e.currentTarget.height] as const;
  };
  const sigStart = (e: React.PointerEvent<HTMLCanvasElement>) => {
    drawing.current = true;
    const ctx2 = e.currentTarget.getContext('2d')!;
    ctx2.lineWidth = 2.5;
    ctx2.lineCap = 'round';
    ctx2.strokeStyle = '#111';
    ctx2.beginPath();
    ctx2.moveTo(...pos(e));
  };
  const sigMove = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return;
    const ctx2 = e.currentTarget.getContext('2d')!;
    ctx2.lineTo(...pos(e));
    ctx2.stroke();
    setSigned(true);
  };
  const sigEnd = () => {
    drawing.current = false;
  };
  const clearSig = () => {
    const c = sigRef.current;
    if (c) c.getContext('2d')!.clearRect(0, 0, c.width, c.height);
    setSigned(false);
  };

  const deliver = () => {
    const payload: StatusPayload = { receiver_name: receiver, note };
    if (photo) payload.photo_base64 = photo;
    if (signed && sigRef.current) payload.signature_base64 = sigRef.current.toDataURL('image/png');
    const go = (p: StatusPayload) => act('delivered', p);
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition((g) => go({ ...payload, lat: g.coords.latitude, lng: g.coords.longitude }), () => go(payload), { timeout: 3000 });
    } else go(payload);
  };

  const target = s.status === 'assigned' ? s.pickup : s.delivery;
  const maps = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(addressLine(target))}&travelmode=bicycling`;

  return (
    <div>
      <TopBarMobile type="title" title={<span className="cv-mono">{s.reference}</span>} onMenu={onBack} trailing={<StatusBadge status={s.status} />} />
      <div style={{ padding: 16 }}>
        <Stack gap={16}>
          <Button variant="ghost" size="sm" leftIcon={<ArrowLeft size={14} />} onClick={onBack}>Terug naar stops</Button>
          <div className="cv-panel">
            <div className="cv-subtle" style={{ textTransform: 'uppercase' }}>Ophalen</div>
            <div className="cv-strong">{s.pickup.company || s.pickup.name}</div>
            <div>{addressLine(s.pickup)}</div>
            {s.pickup.instructions && <div className="cv-muted">“{s.pickup.instructions}”</div>}
            <Inline gap={8}>
              {s.pickup.phone && <a href={`tel:${s.pickup.phone}`}><Button size="sm" variant="secondary" leftIcon={<Phone size={14} />}>{s.pickup.phone}</Button></a>}
            </Inline>
          </div>
          <div className="cv-panel">
            <div className="cv-subtle" style={{ textTransform: 'uppercase' }}>Leveren</div>
            <div className="cv-strong">{s.delivery.company || s.delivery.name}</div>
            <div>{addressLine(s.delivery)}</div>
            {s.delivery.instructions && <div className="cv-muted">“{s.delivery.instructions}”</div>}
            <Inline gap={8}>
              {s.delivery.phone && <a href={`tel:${s.delivery.phone}`}><Button size="sm" variant="secondary" leftIcon={<Phone size={14} />}>{s.delivery.phone}</Button></a>}
              <a href={maps} target="_blank" rel="noreferrer"><Button size="sm" variant="secondary" leftIcon={<Navigation size={14} />}>Navigeer</Button></a>
            </Inline>
          </div>
          <div className="cv-panel">
            <Inline gap={6}>
              <Badge variant={s.service === 'express' ? 'warning' : 'neutral'}>{SERVICE_LABEL[s.service]}</Badge>
              <span>{s.parcels.map((p) => `${p.count}× ${p.weight_class.toUpperCase()}`).join(', ')}</span>
              {s.parcels.some((p) => p.cooled) && <Badge variant="info">Gekoeld</Badge>}
              {s.parcels.some((p) => p.fragile) && <Badge variant="warning">Breekbaar</Badge>}
            </Inline>
            <div className="cv-muted" style={{ marginTop: 6 }}>Leveren voor {formatTime(s.delivery_window.end)} · klant {s.customer_name}</div>
            {s.remarks && <div style={{ marginTop: 6 }}>“{s.remarks}”</div>}
          </div>

          {mode === 'view' && isOpen(s.status) && (
            <div className="cv-bigbtn">
              {s.status === 'assigned' && <Button size="lg" loading={busy} onClick={() => act('picked_up')}>Opgehaald</Button>}
              {s.status === 'picked_up' && <Button size="lg" variant="secondary" loading={busy} onClick={() => act('in_transit')}>Onderweg naar levering</Button>}
              {(s.status === 'picked_up' || s.status === 'in_transit') && <Button size="lg" onClick={() => setMode('deliver')}>Geleverd</Button>}
              {(s.status === 'picked_up' || s.status === 'in_transit') && <Button size="lg" variant="outline" onClick={() => setMode('fail')}>Levering mislukt</Button>}
            </div>
          )}

          {mode === 'deliver' && (
            <div className="cv-panel">
              <Stack gap={12}>
                <div className="cv-strong">Bewijs van levering</div>
                <Input label="Ontvangen door" value={receiver} onChange={(e) => setReceiver(e.target.value)} placeholder="Naam van wie tekende" />
                <div>
                  <label className="cv-strong" style={{ display: 'block', marginBottom: 6 }}>Foto</label>
                  <input type="file" accept="image/*" capture="environment" id="cv-photo" style={{ display: 'none' }} onChange={(e) => onPhoto(e.target.files?.[0])} />
                  <label htmlFor="cv-photo"><Button variant="secondary" leftIcon={<Camera size={16} />} onClick={() => document.getElementById('cv-photo')?.click()}>{photo ? 'Andere foto' : 'Foto nemen'}</Button></label>
                  {photo && <img src={photo} alt="" className="cv-photo-preview" style={{ marginTop: 8 }} />}
                </div>
                <div>
                  <Inline justify="space-between"><label className="cv-strong">Handtekening</label><Button variant="ghost" size="sm" onClick={clearSig}>Wis</Button></Inline>
                  <canvas ref={sigRef} className="cv-signature" width={600} height={240} onPointerDown={sigStart} onPointerMove={sigMove} onPointerUp={sigEnd} onPointerLeave={sigEnd} />
                </div>
                <Input label="Notitie" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Afgegeven aan onthaal, in brievenbus, …" />
                <div className="cv-bigbtn">
                  <Button size="lg" loading={busy} onClick={deliver}>Bevestig levering</Button>
                  <Button size="lg" variant="ghost" onClick={() => setMode('view')}>Terug</Button>
                </div>
              </Stack>
            </div>
          )}

          {mode === 'fail' && (
            <div className="cv-panel">
              <Stack gap={12}>
                <div className="cv-strong">Levering mislukt</div>
                <Select label="Reden" value={reason} onChange={setReason} options={FAIL_REASONS} />
                <Input label="Toelichting" value={note} onChange={(e) => setNote(e.target.value)} />
                <div className="cv-bigbtn">
                  <Button size="lg" variant="destructive" loading={busy} onClick={() => act('failed', { reason, note })}>Registreer</Button>
                  <Button size="lg" variant="ghost" onClick={() => setMode('view')}>Terug</Button>
                </div>
              </Stack>
            </div>
          )}
        </Stack>
      </div>
    </div>
  );
}
