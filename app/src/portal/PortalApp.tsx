import { useCallback, useMemo, useState } from 'react';
import { HashRouter, Navigate, Route, Routes, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { BookOpen, Download, LayoutDashboard, Package, Plus, Search, Upload } from 'lucide-react';
import { Alert, Button, EmptyState, FileUpload, Input, Modal, Pagination, Skeleton, StatCard, Table, Tabs, Textarea, type TableColumn } from '@sakaniui/react';
import type { AddressBookEntry, Shipment, ShipmentFilter, ShipmentStatus } from '../domain/types';
import { SERVICE_LABEL, STATUS_LABEL, isOpen } from '../domain/status';
import { AppShell, type NavItem } from '../ui/AppShell';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad, errorMessage } from '../lib/useLoad';
import { useToast } from '../lib/toast';
import { formatDate, formatDateTime, formatMoney, formatTime, formatWindow, addressLine } from '../lib/format';
import { StatusBadge } from '../ui/StatusBadge';
import { PageHeader, KeyValue, Inline, Stack, Section } from '../ui/Page';
import { Drawer } from '../ui/Drawer';
import { BookingForm } from '../ui/BookingForm';
import { RouteCard } from '../ui/RouteCard';
import { Timeline } from '../ui/Timeline';
import { PodView } from '../ui/PodView';

type Row = Shipment & { route?: never };

export function PortalApp() {
  return (
    <HashRouter>
      <Frame />
    </HashRouter>
  );
}

function Frame() {
  const nav = useNavigate();
  const loc = useLocation();
  const ctx = useAppContext();
  const items: NavItem[] = [
    { key: 'overview', label: 'Overzicht', icon: LayoutDashboard },
    { key: 'shipments', label: 'Mijn zendingen', icon: Package },
    { key: 'new', label: 'Nieuwe zending', icon: Plus },
    { key: 'addresses', label: 'Adresboek', icon: BookOpen },
    { key: 'import', label: 'Importeren', icon: Upload },
  ];
  if (!ctx.user.customer_id) {
    return (
      <div className="cv-root" style={{ padding: 32, maxWidth: 560, margin: '0 auto' }}>
        <Alert color="warning" title="Account nog niet gekoppeld" description="Je account is nog niet aan een klant gekoppeld. Contacteer Cargo Velo, dan zetten we dat recht." />
      </div>
    );
  }
  return (
    <AppShell title="Cargo Velo" subtitle="Klantenportaal" nav={items} active={loc.pathname.split('/')[1] || 'overview'} onNavigate={(k) => nav('/' + k)}>
      <Routes>
        <Route path="/" element={<Navigate to="/overview" replace />} />
        <Route path="/overview" element={<Overview />} />
        <Route path="/shipments" element={<Shipments />} />
        <Route path="/shipments/:id" element={<Shipments />} />
        <Route path="/new" element={<NewShipment />} />
        <Route path="/addresses" element={<Addresses />} />
        <Route path="/import" element={<Import />} />
        <Route path="*" element={<Navigate to="/overview" replace />} />
      </Routes>
    </AppShell>
  );
}

function Overview() {
  const api = useApi();
  const ctx = useAppContext();
  const nav = useNavigate();
  const counts = useLoad(() => api.portalCounts(), []);
  const recent = useLoad(() => api.portalList({ per_page: 8, order: 'desc' }), []);
  const c = counts.data;
  const open = c ? ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit'].reduce((n, s) => n + (c.by_status[s as ShipmentStatus] ?? 0), 0) : 0;
  return (
    <div>
      <PageHeader title={`Dag ${ctx.user.name.split(' ')[0]}`} description="Je zendingen bij Cargo Velo" actions={<Button leftIcon={<Plus size={16} />} onClick={() => nav('/new')}>Nieuwe zending</Button>} />
      <div className="cv-grid cv-grid--stats" style={{ marginBottom: 24 }}>
        {c ? (
          <>
            <StatCard title="Vandaag" value={String(c.today)} description="zendingen gepland" trend="flat" delta="vandaag" badgeVariant="neutral" />
            <StatCard title="Open" value={String(open)} description="in behandeling" trend="flat" delta="live" badgeVariant="info" />
            <StatCard title="Onderweg" value={String((c.by_status.picked_up ?? 0) + (c.by_status.in_transit ?? 0))} description="bij een koerier" trend="up" delta="nu" />
            <StatCard title="Geleverd" value={String(c.by_status.delivered ?? 0)} description="totaal" trend="up" delta="✓" />
          </>
        ) : Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} variant="rect" height={110} />)}
      </div>
      <Section title="Recente zendingen" actions={<Button variant="ghost" size="sm" onClick={() => nav('/shipments')}>Alles bekijken</Button>}>
        <ShipmentTable rows={recent.data?.items ?? []} loading={recent.loading} onOpen={(id) => nav(`/shipments/${id}`)} />
      </Section>
    </div>
  );
}

function ShipmentTable({ rows, loading, onOpen }: { rows: Shipment[]; loading: boolean; onOpen: (id: number) => void }) {
  const columns: TableColumn<Row>[] = [
    { key: 'reference', header: 'Referentie', width: '150px', render: (r) => <button type="button" className="cv-link-btn cv-mono cv-strong" onClick={() => onOpen(r.id)}>{r.reference}</button> },
    { key: 'status', header: 'Status', width: '130px', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'route', header: 'Naar', render: (r) => <div className="cv-cell-route"><span>{r.delivery.company || r.delivery.name}</span><small>{addressLine(r.delivery)}</small></div> },
    { key: 'service', header: 'Dienst', width: '100px', render: (r) => <span>{SERVICE_LABEL[r.service]}</span> },
    { key: 'pickup_window', header: 'Ophaling', width: '150px', render: (r) => <span>{r.pickup_window.start ? `${formatDate(r.pickup_window.start)} ${formatTime(r.pickup_window.start)}` : 'ASAP'}</span> },
    { key: 'price_cents', header: 'Prijs', align: 'right', width: '90px', render: (r) => <span>{formatMoney(r.price_cents)}</span> },
  ];
  return (
    <div className="cv-table-wrap">
      {loading && rows.length === 0 ? <div style={{ padding: 16 }}><Skeleton variant="rect" height={160} /></div> : rows.length === 0 ? <EmptyState type="no-data" title="Nog geen zendingen" description="Boek je eerste zending in enkele klikken." /> : <Table<Row> columns={columns} rows={rows as Row[]} rowKey={(r) => r.id} bordered={false} responsive="auto" />}
    </div>
  );
}

function Shipments() {
  const api = useApi();
  const nav = useNavigate();
  const { id } = useParams();
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('search') ?? '');
  const tab = params.get('tab') ?? 'open';
  const filter = useMemo<ShipmentFilter>(() => ({ page: Number(params.get('page') ?? 1), per_page: 25, search: params.get('search') ?? undefined, ...(tab === 'open' ? { open: '1' } : tab === 'delivered' ? { status: ['delivered'] } : {}) }) as ShipmentFilter, [params, tab]);
  const list = useLoad(() => api.portalList(filter), [filter]);
  const pages = list.data ? Math.max(1, Math.ceil(list.data.total / list.data.per_page)) : 1;
  const set = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) v ? next.set(k, v) : next.delete(k);
    if (!('page' in patch)) next.delete('page');
    setParams(next);
  };
  const close = useCallback(() => nav({ pathname: '/shipments', search: params.toString() }), [nav, params]);
  return (
    <div>
      <PageHeader title="Mijn zendingen" actions={<Button leftIcon={<Plus size={16} />} onClick={() => nav('/new')}>Nieuwe zending</Button>} />
      <Tabs items={[{ value: 'open', label: 'Open' }, { value: 'delivered', label: 'Geleverd' }, { value: 'all', label: 'Alles' }]} value={tab} onChange={(v) => set({ tab: v })} />
      <div className="cv-toolbar" style={{ marginTop: 12 }}>
        <div className="cv-toolbar__grow"><Input placeholder="Zoek op referentie of adres…" leadingIcon={<Search />} value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && set({ search })} /></div>
      </div>
      <ShipmentTable rows={list.data?.items ?? []} loading={list.loading} onOpen={(sid) => nav({ pathname: `/shipments/${sid}`, search: params.toString() })} />
      {list.data && list.data.total > 25 && <div className="cv-table-foot"><span className="cv-muted">{list.data.total} zendingen</span><Pagination total={pages} page={list.data.page} onPageChange={(p) => set({ page: String(p) })} /></div>}
      {id && <PortalDrawer id={Number(id)} onClose={close} onChanged={list.reload} />}
    </div>
  );
}

function PortalDrawer({ id, onClose, onChanged }: { id: number; onClose: () => void; onChanged: () => void }) {
  const api = useApi();
  const toast = useToast();
  const load = useLoad(() => api.portalGet(id), [id]);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState('details');
  const s = load.data;
  const act = async (fn: () => Promise<Shipment>, ok: string) => {
    setBusy(true);
    try {
      load.setData(await fn());
      toast.success(ok);
      onChanged();
      setCancelOpen(false);
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Drawer open onClose={onClose} title={s ? <span className="cv-mono">{s.reference}</span> : 'Laden…'} subtitle={s ? `${SERVICE_LABEL[s.service]} · ${STATUS_LABEL[s.status]}` : undefined} width={600}
      footer={s && isOpen(s.status) && ['requested', 'confirmed'].includes(s.status) ? <Button variant="outline" onClick={() => setCancelOpen(true)}>Zending annuleren</Button> : undefined}>
      {load.error ? <EmptyState type="error" title="Niet gevonden" description={load.error.message} /> : !s ? <Skeleton variant="rect" height={300} /> : (
        <Stack gap={20}>
          <Inline justify="space-between"><StatusBadge status={s.status} solid /><span className="cv-strong" style={{ fontSize: 18 }}>{formatMoney(s.price_cents)}</span></Inline>
          <div className="cv-panel"><RouteCard pickup={s.pickup} delivery={s.delivery} /></div>
          <Tabs items={[{ value: 'details', label: 'Details' }, { value: 'timeline', label: 'Opvolging' }, { value: 'pod', label: 'Bewijs van levering' }]} value={tab} onChange={setTab} />
          {tab === 'details' && <KeyValue rows={[['Ophaling', formatWindow(s.pickup_window)], ['Levering', s.delivery_window.end ? `uiterlijk ${formatDateTime(s.delivery_window.end)}` : '—'], ['Pakketten', s.parcels.map((p) => `${p.count}× ${p.weight_class.toUpperCase()}${p.fragile ? ' breekbaar' : ''}${p.cooled ? ' gekoeld' : ''}`).join(', ')], ['Koerier', s.courier_name ?? 'nog niet toegewezen'], ['Opmerkingen', s.remarks || '—'], ['Track & trace voor ontvanger', <a href={`?view=tracking&t=${s.tracking_token}`} target="_blank" rel="noreferrer">link openen</a>]]} />}
          {tab === 'timeline' && (
            <Stack>
              <Timeline events={s.events ?? []} />
              {isOpen(s.status) && (
                <div className="cv-panel">
                  <Textarea label="Bericht aan dispatch" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                  <Inline justify="flex-end"><Button size="sm" variant="secondary" disabled={!note.trim()} loading={busy} onClick={() => act(() => api.portalNote(s.id, note), 'Bericht verstuurd').then(() => setNote(''))}>Versturen</Button></Inline>
                </div>
              )}
            </Stack>
          )}
          {tab === 'pod' && <PodView shipment={s} scope="portal" />}
        </Stack>
      )}
      <Modal open={cancelOpen} onClose={() => setCancelOpen(false)} title="Zending annuleren?" variant="destructive" description="Annuleren kan zolang de zending niet aan een koerier is toegewezen." confirmLabel="Annuleren" cancelLabel="Terug" confirmLoading={busy} onConfirm={() => s && act(() => api.portalCancel(s.id, reason), 'Zending geannuleerd')}>
        <Input label="Reden (optioneel)" value={reason} onChange={(e) => setReason(e.target.value)} />
      </Modal>
    </Drawer>
  );
}

function NewShipment() {
  const api = useApi();
  const ctx = useAppContext();
  const nav = useNavigate();
  const toast = useToast();
  const book = useLoad(() => api.addresses(), []);
  return (
    <div>
      <PageHeader title="Nieuwe zending" description="Contractklanten worden meteen bevestigd. Je krijgt de referentie en een track & trace link per mail." />
      <BookingForm mode="portal" services={ctx.services} addressBook={book.data ?? []} quote={(i) => api.portalQuote(i)} onCancel={() => nav('/overview')}
        onSubmit={async (input) => {
          const s = await api.portalCreate(input);
          toast.success(`Zending ${s.reference} ${s.status === 'confirmed' ? 'bevestigd' : 'aangevraagd'}`);
          nav(`/shipments/${s.id}`);
        }} />
    </div>
  );
}

function Addresses() {
  const api = useApi();
  const toast = useToast();
  const list = useLoad(() => api.addresses(), []);
  const [editing, setEditing] = useState<Partial<AddressBookEntry> | null>(null);
  const [busy, setBusy] = useState(false);
  const f = (k: keyof AddressBookEntry) => ({ value: String(editing?.[k] ?? ''), onChange: (e: React.ChangeEvent<HTMLInputElement>) => setEditing({ ...editing, [k]: e.target.value }) });
  const save = async () => {
    if (!editing) return;
    setBusy(true);
    try {
      await api.addressCreate(editing as Omit<AddressBookEntry, 'id' | 'customer_id'>);
      toast.success('Adres opgeslagen');
      setEditing(null);
      list.reload();
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <div>
      <PageHeader title="Adresboek" description="Vaste ophaal- en leveradressen, in één klik te kiezen bij het boeken." actions={<Button leftIcon={<Plus size={16} />} onClick={() => setEditing({ label: '', name: '', company: '', street: '', number: '', box: '', postcode: '', city: '', phone: '', email: '', instructions: '' })}>Adres toevoegen</Button>} />
      <div className="cv-grid cv-grid--2">
        {(list.data ?? []).map((a) => (
          <div key={a.id} className="cv-panel">
            <Inline justify="space-between"><span className="cv-strong">{a.label}</span><Button variant="ghost" size="sm" onClick={async () => { await api.addressDelete(a.id); list.reload(); }}>Verwijder</Button></Inline>
            <div>{a.company ? `${a.company} · ` : ''}{a.name}</div>
            <div className="cv-muted">{addressLine(a)}</div>
            {a.instructions && <div className="cv-subtle">“{a.instructions}”</div>}
          </div>
        ))}
        {list.data && list.data.length === 0 && <EmptyState type="no-data" title="Nog geen adressen" />}
      </div>
      <Drawer open={editing !== null} onClose={() => setEditing(null)} title="Adres toevoegen" width={520} footer={<><Button loading={busy} onClick={save}>Opslaan</Button><Button variant="ghost" onClick={() => setEditing(null)}>Annuleren</Button></>}>
        {editing && (
          <Stack gap={12}>
            <Input label="Label" required {...f('label')} placeholder="bv. Labo hoofdgebouw" />
            <div className="cv-grid cv-grid--form"><Input label="Contactpersoon" required {...f('name')} /><Input label="Bedrijf" {...f('company')} /></div>
            <div className="cv-grid cv-grid--form" style={{ gridTemplateColumns: '2fr 1fr 1fr' }}><Input label="Straat" required {...f('street')} /><Input label="Nr" required {...f('number')} /><Input label="Bus" {...f('box')} /></div>
            <div className="cv-grid cv-grid--form" style={{ gridTemplateColumns: '1fr 2fr' }}><Input label="Postcode" required {...f('postcode')} /><Input label="Gemeente" required {...f('city')} /></div>
            <div className="cv-grid cv-grid--form"><Input label="Telefoon" {...f('phone')} /><Input label="E-mail" {...f('email')} /></div>
            <Textarea label="Instructies" rows={2} value={editing.instructions ?? ''} onChange={(e) => setEditing({ ...editing, instructions: e.target.value })} />
          </Stack>
        )}
      </Drawer>
    </div>
  );
}

function Import() {
  const api = useApi();
  const [result, setResult] = useState<Awaited<ReturnType<typeof api.portalImport>> | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [key, setKey] = useState(0);
  const onFiles = async (files: File[]) => {
    const file = files[0];
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      setResult(await api.portalImport(await file.text()));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
      setKey((k) => k + 1);
    }
  };
  return (
    <div style={{ maxWidth: 760 }}>
      <PageHeader title="Zendingen importeren" description="Upload een CSV met één zending per rij. Maximaal 500 rijen per bestand." actions={<a href={api.portalImportTemplateUrl()} download="cargovelo-import-sjabloon.csv" className="cv-link-btn"><Button variant="secondary" leftIcon={<Download size={16} />}>Sjabloon downloaden</Button></a>} />
      <div className="cv-panel">
        <FileUpload key={key} accept=".csv,text/csv" hint="CSV, scheidingsteken ; of , (max. 2 MB)" onFilesChange={onFiles} />
        {busy && <p className="cv-muted" style={{ marginTop: 12 }}>Bezig met importeren…</p>}
        {error && <div style={{ marginTop: 12 }}><Alert color="danger" title={error} /></div>}
      </div>
      {result && (
        <div className="cv-panel" style={{ marginTop: 16 }}>
          <Alert color={result.failed ? 'warning' : 'success'} title={`${result.created} zendingen aangemaakt, ${result.failed} mislukt`} />
          <ul style={{ margin: '12px 0 0', paddingLeft: 18 }}>
            {result.rows.filter((r) => !r.ok).map((r) => <li key={r.line}>Rij {r.line}: {r.error}</li>)}
          </ul>
        </div>
      )}
    </div>
  );
}
