import { useMemo, useState, type DragEvent } from 'react';
import { Clock, MapPin, Package } from 'lucide-react';
import { Avatar, Badge, BoardCard, Button, CardMetaItem, SegmentedControl, Skeleton } from '@sakaniui/react';
import type { ReactNode } from 'react';
import type { Courier, Shipment } from '../domain/types';
import { SERVICE_LABEL, STATUS_LABEL } from '../domain/status';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad, errorMessage } from '../lib/useLoad';
import { useToast } from '../lib/toast';
import { formatTime, initials, todayIso } from '../lib/format';
import { PageHeader } from '../ui/Page';
import { Drawer } from '../ui/Drawer';
import { ShipmentDetail } from './ShipmentDetail';

/** Sakani's BoardColumn hard-codes an English "Add task" footer; this is the same shape without it. */
function Column({ title, count, dot, empty, children }: { title: ReactNode; count: ReactNode; dot: string; empty: boolean; children: ReactNode }) {
  return (
    <section className="cv-col">
      <header className="cv-col__head">
        <span className="cv-dot" style={{ background: dot, marginRight: 8 }} />
        <span className="cv-col__title">{title}</span>
        <span className="cv-col__count">{count}</span>
      </header>
      <div className="cv-col__body">{empty ? <div className="cv-col__empty">Sleep een zending hierheen</div> : children}</div>
    </section>
  );
}

/**
 * Today's board per hub: requests to confirm, unassigned work, one column per courier.
 * Native HTML5 drag-and-drop (Sakani's BoardCard has no DnD); dropping on a column assigns,
 * dropping on a card inserts before it and re-sequences that courier's stops.
 */
export function DispatchBoard() {
  const api = useApi();
  const ctx = useAppContext();
  const toast = useToast();
  const [hub, setHub] = useState(ctx.user.hub ?? ctx.hubs[0]?.code ?? '');
  const [day, setDay] = useState<'today' | 'tomorrow'>('today');
  const [dragging, setDragging] = useState<number | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);

  const date = useMemo(() => {
    if (day === 'today') return todayIso();
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
  }, [day]);

  const list = useLoad(() => api.opsList({ hub, date_from: date, date_to: date, per_page: 200, order: 'asc', open: '1' } as never), [hub, date]);
  const couriers = useLoad(() => api.couriers(hub), [hub]);
  const detail = useLoad(() => (openId ? api.opsGet(openId) : Promise.resolve(null)), [openId]);

  const items = list.data?.items ?? [];
  const requested = items.filter((s) => s.status === 'requested');
  const unassigned = items.filter((s) => s.status === 'confirmed');
  const byCourier = (c: Courier) => items.filter((s) => s.courier_id === c.id).sort((a, b) => (a.stop_sequence ?? 999) - (b.stop_sequence ?? 999));

  const patch = (s: Shipment) => list.setData((prev) => (prev ? { ...prev, items: prev.items.map((x) => (x.id === s.id ? { ...x, ...s, events: undefined } : x)) } : prev));

  const assign = async (id: number, courierId: number | null, beforeId?: number) => {
    try {
      const s = await api.opsAssign(id, courierId);
      patch(s);
      if (courierId && beforeId) {
        const c = couriers.data?.find((x) => x.id === courierId);
        if (c) {
          const order = byCourier(c).map((x) => x.id).filter((x) => x !== id);
          const idx = order.indexOf(beforeId);
          order.splice(idx < 0 ? order.length : idx, 0, id);
          await api.courierReorder(courierId, order);
          list.reload();
        }
      }
      toast.success(courierId ? 'Toegewezen' : 'Losgekoppeld');
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    }
  };
  const confirm = async (id: number) => {
    try {
      patch(await api.opsStatus(id, 'confirmed'));
      toast.success('Bevestigd');
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    }
  };

  const onDragStart = (e: DragEvent, id: number) => {
    setDragging(id);
    e.dataTransfer.setData('text/plain', String(id));
    e.dataTransfer.effectAllowed = 'move';
  };
  const dropProps = (key: string, courierId: number | null, beforeId?: number) => ({
    onDragOver: (e: DragEvent) => {
      e.preventDefault();
      e.stopPropagation();
      setOver(key);
    },
    onDragLeave: () => setOver(null),
    onDrop: (e: DragEvent) => {
      e.preventDefault();
      e.stopPropagation();
      setOver(null);
      const id = Number(e.dataTransfer.getData('text/plain')) || dragging;
      setDragging(null);
      if (id) assign(id, courierId, beforeId);
    },
  });

  const card = (s: Shipment, courierId: number | null) => (
    <div key={s.id} draggable={s.status !== 'picked_up' && s.status !== 'in_transit'} className="cv-board__card" onDragStart={(e) => onDragStart(e, s.id)} {...(courierId ? dropProps(`card-${s.id}`, courierId, s.id) : {})}>
      <BoardCard
        type="default"
        state={dragging === s.id ? 'dragging' : s.status === 'in_transit' || s.status === 'picked_up' ? 'selected' : 'default'}
        title={<span className="cv-mono">{s.reference}</span>}
        leading={s.stop_sequence ? <span className="cv-stop__seq">{s.stop_sequence}</span> : undefined}
        trailing={<Badge variant={s.service === 'express' ? 'warning' : 'neutral'}>{SERVICE_LABEL[s.service]}</Badge>}
        description={<span>{s.customer_name}<br />{s.pickup.street} {s.pickup.number} → {s.delivery.street} {s.delivery.number}</span>}
        tags={<>{s.status !== 'confirmed' && s.status !== 'assigned' && <Badge variant="accent">{STATUS_LABEL[s.status]}</Badge>}{s.parcels.some((p) => p.cooled) && <Badge variant="info">Gekoeld</Badge>}{s.parcels.some((p) => p.fragile) && <Badge variant="warning">Breekbaar</Badge>}{s.exception && <Badge variant="danger">{s.exception}</Badge>}</>}
        meta={<><CardMetaItem icon={<Clock size={13} />}>{s.pickup_window.start ? formatTime(s.pickup_window.start) : 'ASAP'} → {formatTime(s.delivery_window.end)}</CardMetaItem><CardMetaItem icon={<Package size={13} />}>{s.parcels.reduce((n, p) => n + p.count, 0)}</CardMetaItem><CardMetaItem icon={<MapPin size={13} />}>{s.delivery.city}</CardMetaItem></>}
        onClick={() => setOpenId(s.id)}
      />
    </div>
  );

  return (
    <div style={{ padding: '24px 28px' }}>
      <PageHeader
        title="Dispatch"
        description={`${items.length} open zendingen · hub ${hub}`}
        actions={<>
          <SegmentedControl options={[{ value: 'today', label: 'Vandaag' }, { value: 'tomorrow', label: 'Morgen' }]} value={day} onChange={(v) => setDay(v as 'today' | 'tomorrow')} />
          <SegmentedControl options={ctx.hubs.map((h) => ({ value: h.code, label: h.city }))} value={hub} onChange={setHub} />
          <Button variant="secondary" size="sm" onClick={list.reload}>Vernieuwen</Button>
        </>}
      />
      {list.loading && !list.data ? (
        <div className="cv-board">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} variant="rect" width={320} height={400} />)}</div>
      ) : (
        <div className="cv-board">
          <div className={`cv-board__col ${over === 'requested' ? 'cv-board__drop' : ''}`}>
            <Column title="Aanvragen" count={<Badge variant="warning">{requested.length}</Badge>} dot="var(--color-warning-solid)" empty={requested.length === 0}>
              {requested.map((s) => (
                <div key={s.id}>
                  {card(s, null)}
                  <div style={{ padding: '0 0 8px' }}><Button size="sm" variant="primary" onClick={() => confirm(s.id)}>Bevestigen</Button></div>
                </div>
              ))}
            </Column>
          </div>
          <div className={`cv-board__col ${over === 'unassigned' ? 'cv-board__drop' : ''}`} {...dropProps('unassigned', null)}>
            <Column title="Toe te wijzen" count={<Badge variant="info">{unassigned.length}</Badge>} dot="var(--color-info-solid)" empty={unassigned.length === 0}>
              {unassigned.map((s) => card(s, null))}
            </Column>
          </div>
          {(couriers.data ?? []).filter((c) => c.active).map((c) => {
            const stops = byCourier(c);
            const done = items.filter((s) => s.courier_id === c.id && s.status === 'delivered').length;
            return (
              <div key={c.id} className={`cv-board__col ${over === `c-${c.id}` ? 'cv-board__drop' : ''}`} {...dropProps(`c-${c.id}`, c.id)}>
                <Column title={<span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}><Avatar size="sm" initials={initials(c.name)} />{c.name}</span>} count={<Badge>{stops.length}{done ? ` · ${done} ✓` : ''}</Badge>} dot="var(--color-chart-1)" empty={stops.length === 0}>
                  {stops.map((s) => card(s, c.id))}
                </Column>
              </div>
            );
          })}
        </div>
      )}
      <Drawer open={openId !== null} onClose={() => setOpenId(null)} title={detail.data ? <span className="cv-mono">{detail.data.reference}</span> : 'Laden…'} subtitle={detail.data?.customer_name} width={620}>
        {detail.data ? <ShipmentDetail shipment={detail.data} onChange={(s) => { detail.setData(s); patch(s); }} /> : <Skeleton variant="rect" height={300} />}
      </Drawer>
    </div>
  );
}
