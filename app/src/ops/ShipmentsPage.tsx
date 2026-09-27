import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Globe, Plus, Search, Star, AlertTriangle, CalendarDays } from 'lucide-react';
import { Button, EmptyState, FilterChip, Input, Pagination, Select, Skeleton, Table, type TableColumn } from '@sakaniui/react';
import type { Customer, Shipment, ShipmentFilter, ShipmentStatus, StatusCounts } from '../domain/types';
import { CHANNEL_LABEL, SERVICE_LABEL, STATUS_LABEL, STATUS_ORDER } from '../domain/status';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad } from '../lib/useLoad';
import { formatMoney, formatTime, formatDate, todayIso } from '../lib/format';
import { StatusBadge, StatusDot } from '../ui/StatusBadge';
import { PageHeader } from '../ui/Page';
import { Drawer } from '../ui/Drawer';
import { ShipmentDetail } from './ShipmentDetail';
import { BookingForm } from '../ui/BookingForm';
import { useToast } from '../lib/toast';

type Row = Shipment & { route?: never; when?: never; actions?: never };

const SAVED_VIEWS: Array<{ key: string; label: string; icon: typeof Star; params: Record<string, string> }> = [
  { key: 'today', label: 'Vandaag', icon: CalendarDays, params: { date_from: todayIso(), date_to: todayIso() } },
  { key: 'web', label: 'Website-aanvragen', icon: Globe, params: { status: 'requested' } },
  { key: 'exceptions', label: 'Uitzonderingen', icon: AlertTriangle, params: { exception: '1' } },
  { key: 'open', label: 'Alle open', icon: Star, params: { open: '1' } },
];

export function ShipmentsPage({ onChanged }: { onChanged: () => void }) {
  const api = useApi();
  const ctx = useAppContext();
  const nav = useNavigate();
  const { id } = useParams();
  const [params] = useSearchParams();
  const toast = useToast();
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState(params.get('search') ?? '');

  const filter = useMemo<ShipmentFilter>(() => {
    const f: ShipmentFilter = { page: Number(params.get('page') ?? 1), per_page: 25 };
    const status = params.get('status');
    if (status) f.status = status.split(',') as ShipmentStatus[];
    const service = params.get('service');
    if (service) f.service = service.split(',') as ShipmentFilter['service'];
    if (params.get('hub')) f.hub = params.get('hub')!;
    if (params.get('customer_id')) f.customer_id = Number(params.get('customer_id'));
    if (params.get('date_from')) f.date_from = params.get('date_from')!;
    if (params.get('date_to')) f.date_to = params.get('date_to')!;
    if (params.get('search')) f.search = params.get('search')!;
    if (params.get('exception')) f.exception = params.get('exception') === '1';
    if (params.get('open')) (f as ShipmentFilter & { open?: string }).open = '1';
    return f;
  }, [params]);

  const list = useLoad(() => api.opsList(filter), [filter]);
  const counts = useLoad(() => api.opsCounts(filter.hub), [filter.hub, list.data]);
  const customers = useLoad(() => api.customers(), []);

  useEffect(() => {
    const t = window.setTimeout(() => {
      if ((params.get('search') ?? '') !== search) set({ search, page: '' });
    }, 300);
    return () => window.clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  // Any filter change also closes an open drawer: the row it belonged to may no longer be in the list.
  const set = useCallback(
    (patch: Record<string, string>) => {
      const next = new URLSearchParams(params);
      for (const [k, v] of Object.entries(patch)) v ? next.set(k, v) : next.delete(k);
      if (!('page' in patch)) next.delete('page');
      nav({ pathname: '/shipments', search: next.toString() });
    },
    [params, nav],
  );
  const only = (patch: Record<string, string>) => nav({ pathname: '/shipments', search: new URLSearchParams(patch).toString() });

  const selected = useMemo(() => (id ? Number(id) : null), [id]);
  const close = useCallback(() => nav({ pathname: '/shipments', search: params.toString() }), [nav, params]);
  const onUpdated = useCallback(
    (s: Shipment) => {
      list.setData((prev) => (prev ? { ...prev, items: prev.items.map((x) => (x.id === s.id ? { ...x, ...s, events: undefined } : x)) } : prev));
      onChanged();
    },
    [list, onChanged],
  );

  const columns: TableColumn<Row>[] = [
    { key: 'reference', header: 'Referentie', width: '140px', render: (r) => (
      <button type="button" className="cv-link-btn" onClick={() => nav({ pathname: `/shipments/${r.id}`, search: params.toString() })}>
        <span className="cv-mono cv-strong">{r.reference}</span>
        <div className="cv-subtle">{CHANNEL_LABEL[r.channel]} · {r.hub}</div>
      </button>
    ) },
    { key: 'status', header: 'Status', width: '120px', render: (r) => <><StatusBadge status={r.status} />{r.exception && <div className="cv-subtle" style={{ color: 'var(--color-danger-fg)' }}>{r.exception}</div>}</> },
    { key: 'customer_name', header: 'Klant', width: '160px', render: (r) => <span>{r.customer_name}</span> },
    { key: 'route', header: 'Route', render: (r) => (
      <div className="cv-cell-route">
        <span>{r.pickup.city} → {r.delivery.city}</span>
        <small>{r.pickup.street} {r.pickup.number} → {r.delivery.street} {r.delivery.number}</small>
      </div>
    ) },
    { key: 'service', header: 'Dienst', width: '90px', render: (r) => <span>{SERVICE_LABEL[r.service]}</span> },
    { key: 'when', header: 'Ophaling', width: '150px', render: (r) => <div className="cv-cell-route"><span>{r.pickup_window.start ? `${formatDate(r.pickup_window.start)} · ${formatTime(r.pickup_window.start)}` : 'ASAP'}</span><small>tot {formatTime(r.delivery_window.end)}</small></div> },
    { key: 'courier_name', header: 'Koerier', width: '130px', render: (r) => <span className={r.courier_name ? '' : 'cv-muted'}>{r.courier_name ?? '—'}</span> },
    { key: 'price_cents', header: 'Prijs', align: 'right', width: '80px', render: (r) => <span>{formatMoney(r.price_cents)}</span> },
  ];

  const pages = list.data ? Math.max(1, Math.ceil(list.data.total / list.data.per_page)) : 1;
  const activeChips = [
    filter.hub && { k: 'hub', label: `Hub: ${filter.hub}` },
    filter.service?.length && { k: 'service', label: `Dienst: ${filter.service.map((s) => SERVICE_LABEL[s]).join(', ')}` },
    filter.customer_id && { k: 'customer_id', label: `Klant: ${customers.data?.find((c) => c.id === filter.customer_id)?.name ?? filter.customer_id}` },
    filter.date_from && { k: 'date_from', label: `Vanaf ${filter.date_from}` },
    filter.date_to && { k: 'date_to', label: `Tot ${filter.date_to}` },
    filter.exception !== undefined && { k: 'exception', label: 'Met uitzondering' },
  ].filter(Boolean) as Array<{ k: string; label: string }>;

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: 24, alignItems: 'start' }}>
      <ViewsColumn counts={counts.data} params={params} only={only} set={set} filter={filter} customers={customers.data ?? []} hubs={ctx.hubs.map((h) => h.code)} />

      <div style={{ minWidth: 0 }}>
        <PageHeader title="Zendingen" description={list.data ? `${list.data.total} zendingen` : ' '} actions={<Button leftIcon={<Plus size={16} />} onClick={() => setCreating(true)}>Nieuwe zending</Button>} />
        <div className="cv-toolbar">
          <div className="cv-toolbar__grow"><Input placeholder="Zoek op referentie, klant, adres…" leadingIcon={<Search />} value={search} onChange={(e) => setSearch(e.target.value)} /></div>
          {activeChips.map((c) => <FilterChip key={c.k} type="active" onRemove={() => set({ [c.k]: '' })}>{c.label}</FilterChip>)}
          {activeChips.length > 0 && <Button variant="ghost" size="sm" onClick={() => only({})}>Wis filters</Button>}
        </div>

        <div className="cv-table-wrap">
          {list.loading && !list.data ? (
            <div style={{ padding: 16, display: 'grid', gap: 10 }}>{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} variant="rect" height={44} />)}</div>
          ) : list.error ? (
            <EmptyState type="error" title="Kon zendingen niet laden" description={list.error.message} actionLabel="Opnieuw" onAction={list.reload} />
          ) : list.data && list.data.items.length === 0 ? (
            <EmptyState type="no-results" title="Geen zendingen" description="Geen zendingen voor deze filters." actionLabel="Wis filters" onAction={() => only({})} />
          ) : (
            <Table<Row> columns={columns} rows={(list.data?.items ?? []) as Row[]} rowKey={(r) => r.id} bordered={false} responsive="auto" />
          )}
          {list.data && list.data.total > 0 && (
            <div className="cv-table-foot">
              <span className="cv-muted">{(list.data.page - 1) * list.data.per_page + 1}–{Math.min(list.data.page * list.data.per_page, list.data.total)} van {list.data.total}</span>
              <Pagination total={pages} page={list.data.page} onPageChange={(p) => set({ page: String(p) })} />
            </div>
          )}
        </div>
      </div>

      {selected !== null && <ShipmentDrawerHost id={selected} onClose={close} onUpdated={onUpdated} />}

      <Drawer open={creating} onClose={() => setCreating(false)} title="Nieuwe zending" subtitle="Telefonische of manuele boeking" width={900}>
        <BookingForm
          mode="ops"
          services={ctx.services}
          customers={customers.data ?? []}
          quote={(i) => api.opsQuote(i)}
          onCancel={() => setCreating(false)}
          onSubmit={async (input) => {
            const s = await api.opsCreate(input, 'phone');
            toast.success(`Zending ${s.reference} aangemaakt`);
            setCreating(false);
            list.reload();
            onChanged();
            nav({ pathname: `/shipments/${s.id}`, search: params.toString() });
          }}
        />
      </Drawer>
    </div>
  );
}

function ShipmentDrawerHost({ id, onClose, onUpdated }: { id: number; onClose: () => void; onUpdated: (s: Shipment) => void }) {
  const api = useApi();
  const load = useLoad(() => api.opsGet(id), [id]);
  return (
    <Drawer
      open
      onClose={onClose}
      title={load.data ? <span className="cv-mono">{load.data.reference}</span> : 'Laden…'}
      subtitle={load.data ? `${load.data.customer_name} · ${SERVICE_LABEL[load.data.service]} · ${CHANNEL_LABEL[load.data.channel]}` : undefined}
      width={620}
      leading={load.data ? <StatusDot status={load.data.status} /> : undefined}
    >
      {load.error ? <EmptyState type="error" title="Niet gevonden" description={load.error.message} /> : load.data ? (
        <ShipmentDetail
          shipment={load.data}
          onChange={(s) => {
            load.setData(s);
            onUpdated(s);
          }}
        />
      ) : (
        <Skeleton variant="rect" height={300} />
      )}
    </Drawer>
  );
}

function ViewsColumn({ counts, params, only, set, filter, customers, hubs }: { counts: StatusCounts | null; params: URLSearchParams; only: (p: Record<string, string>) => void; set: (p: Record<string, string>) => void; filter: ShipmentFilter; customers: Customer[]; hubs: string[] }) {
  const isStatus = (s: string) => params.get('status') === s && !params.get('exception') && !params.get('open') && !params.get('date_from');
  const all = !params.get('status') && !params.get('exception') && !params.get('open') && !params.get('date_from');
  const total = counts ? Object.values(counts.by_status).reduce((a, b) => a + b, 0) : 0;
  return (
    <div className="cv-filters">
      <div className="cv-subtle" style={{ padding: '4px 10px', textTransform: 'uppercase', letterSpacing: '.04em' }}>Alle zendingen</div>
      <div className="cv-viewlist">
        <button type="button" className={`cv-viewlist__item ${all ? 'cv-viewlist__item--active' : ''}`} onClick={() => only({})}><span>Alles</span><span className="cv-viewlist__count">{total}</span></button>
        {STATUS_ORDER.map((s) => (
          <button type="button" key={s} className={`cv-viewlist__item ${isStatus(s) ? 'cv-viewlist__item--active' : ''}`} onClick={() => only({ status: s })}>
            <span><StatusDot status={s} />{STATUS_LABEL[s]}</span>
            <span className={`cv-viewlist__count ${isStatus(s) ? 'cv-viewlist__count--pill' : ''}`}>{counts?.by_status[s] ?? 0}</span>
          </button>
        ))}
      </div>
      <div className="cv-subtle" style={{ padding: '12px 10px 4px', textTransform: 'uppercase', letterSpacing: '.04em' }}>Opgeslagen weergaven</div>
      <div className="cv-viewlist">
        {SAVED_VIEWS.map((v) => {
          const active = Object.entries(v.params).every(([k, val]) => params.get(k) === val);
          const Icon = v.icon;
          return (
            <button type="button" key={v.key} className={`cv-viewlist__item ${active ? 'cv-viewlist__item--active' : ''}`} onClick={() => only(v.params)}>
              <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}><Icon size={14} />{v.label}</span>
              {v.key === 'exceptions' && <span className="cv-viewlist__count">{counts?.exceptions ?? 0}</span>}
              {v.key === 'today' && <span className="cv-viewlist__count">{counts?.today ?? 0}</span>}
              {v.key === 'web' && <span className="cv-viewlist__count">{counts?.by_status.requested ?? 0}</span>}
            </button>
          );
        })}
      </div>
      <div className="cv-subtle" style={{ padding: '12px 10px 4px', textTransform: 'uppercase', letterSpacing: '.04em' }}>Filters</div>
      <div style={{ display: 'grid', gap: 8, padding: '0 4px' }}>
        <Select label="Hub" size="sm" placeholder="Alle hubs" value={filter.hub ?? ''} onChange={(v) => set({ hub: v })} options={[{ value: '', label: 'Alle hubs' }, ...hubs.map((h) => ({ value: h, label: h }))]} />
        <Select label="Dienst" size="sm" placeholder="Alle diensten" value={filter.service?.[0] ?? ''} onChange={(v) => set({ service: v })} options={[{ value: '', label: 'Alle diensten' }, ...Object.entries(SERVICE_LABEL).map(([value, label]) => ({ value, label }))]} />
        <Select label="Klant" size="sm" placeholder="Alle klanten" value={filter.customer_id ? String(filter.customer_id) : ''} onChange={(v) => set({ customer_id: v })} options={[{ value: '', label: 'Alle klanten' }, ...customers.map((c) => ({ value: String(c.id), label: c.name }))]} />
        <Input label="Van" size="sm" type="date" value={filter.date_from ?? ''} onChange={(e) => set({ date_from: e.target.value })} />
        <Input label="Tot" size="sm" type="date" value={filter.date_to ?? ''} onChange={(e) => set({ date_to: e.target.value })} />
      </div>
    </div>
  );
}
