import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import { Badge, Button, Checkbox, Input, Select, Skeleton, Table, Textarea, type TableColumn } from '@sakaniui/react';
import type { Courier, Customer, PriceList, ServiceCode } from '../domain/types';
import { SERVICE_LABEL } from '../domain/status';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad, errorMessage } from '../lib/useLoad';
import { useToast } from '../lib/toast';
import { formatMoney } from '../lib/format';
import { PageHeader, Inline, Stack } from '../ui/Page';
import { Drawer } from '../ui/Drawer';

type CustomerRow = Customer & { actions?: never };
type CourierRow = Courier & { actions?: never };

const EMPTY_CUSTOMER: Partial<Customer> & { user_ids?: number[] } = { name: '', type: 'account', email: '', phone: '', hub: '', price_list_id: null, vat: '', billing_address: '', notes: '', active: true };

export function CustomersPage() {
  const api = useApi();
  const ctx = useAppContext();
  const toast = useToast();
  const [search, setSearch] = useState('');
  const list = useLoad(() => api.customers(search), [search]);
  const lists = useLoad(() => api.priceLists(), []);
  const [editing, setEditing] = useState<(Partial<Customer> & { user_ids?: number[]; userIdsText?: string }) | null>(null);
  const [busy, setBusy] = useState(false);
  const canManage = ctx.user.role === 'admin';

  const save = async () => {
    if (!editing) return;
    setBusy(true);
    try {
      const payload = { ...editing, user_ids: editing.userIdsText ? editing.userIdsText.split(',').map((s) => Number(s.trim())).filter(Boolean) : undefined };
      delete (payload as { userIdsText?: string }).userIdsText;
      editing.id ? await api.customerUpdate(editing.id, payload) : await api.customerCreate(payload);
      toast.success('Klant opgeslagen');
      setEditing(null);
      list.reload();
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const columns: TableColumn<CustomerRow>[] = [
    { key: 'name', header: 'Klant', render: (c) => <button type="button" className="cv-link-btn" onClick={() => setEditing({ ...c })}><span className="cv-strong">{c.name}</span><div className="cv-subtle">{c.email}</div></button> },
    { key: 'type', header: 'Type', width: '120px', render: (c) => <Badge variant={c.type === 'account' ? 'accent' : 'neutral'}>{c.type === 'account' ? 'Contract' : 'Occasioneel'}</Badge> },
    { key: 'hub', header: 'Hub', width: '80px' },
    { key: 'price_list_id', header: 'Prijslijst', width: '180px', render: (c) => <span>{lists.data?.find((l) => l.id === c.price_list_id)?.name ?? <span className="cv-muted">Standaard</span>}</span> },
    { key: 'phone', header: 'Telefoon', width: '140px' },
    { key: 'active', header: 'Actief', width: '80px', render: (c) => <span>{c.active ? 'Ja' : 'Nee'}</span> },
  ];

  return (
    <div>
      <PageHeader title="Klanten" description="Contractklanten boeken zelf in het portaal en worden automatisch bevestigd. Occasionele klanten ontstaan uit websiteboekingen." actions={canManage && <Button leftIcon={<Plus size={16} />} onClick={() => setEditing({ ...EMPTY_CUSTOMER })}>Nieuwe klant</Button>} />
      <div className="cv-toolbar"><div className="cv-toolbar__grow"><Input placeholder="Zoek klant…" value={search} onChange={(e) => setSearch(e.target.value)} /></div></div>
      <div className="cv-table-wrap">
        {list.data ? <Table<CustomerRow> columns={columns} rows={list.data as CustomerRow[]} rowKey={(c) => c.id} bordered={false} responsive="auto" /> : <div style={{ padding: 16 }}><Skeleton variant="rect" height={200} /></div>}
      </div>
      <Drawer open={editing !== null} onClose={() => setEditing(null)} title={editing?.id ? editing.name : 'Nieuwe klant'} width={560}
        footer={canManage ? <><Button loading={busy} onClick={save}>Opslaan</Button><Button variant="ghost" onClick={() => setEditing(null)}>Sluiten</Button></> : <Button variant="ghost" onClick={() => setEditing(null)}>Sluiten</Button>}>
        {editing && (
          <Stack gap={12}>
            <Input label="Naam" required value={editing.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} disabled={!canManage} />
            <div className="cv-grid cv-grid--form">
              <Select label="Type" value={editing.type ?? 'account'} onChange={(v) => setEditing({ ...editing, type: v as Customer['type'] })} options={[{ value: 'account', label: 'Contract (zelf boeken, auto-bevestigd)' }, { value: 'occasional', label: 'Occasioneel (via website)' }]} disabled={!canManage} />
              <Select label="Hub" value={editing.hub ?? ''} onChange={(v) => setEditing({ ...editing, hub: v })} options={ctx.hubs.map((h) => ({ value: h.code, label: h.name }))} disabled={!canManage} />
            </div>
            <div className="cv-grid cv-grid--form">
              <Input label="E-mail" type="email" value={editing.email ?? ''} onChange={(e) => setEditing({ ...editing, email: e.target.value })} disabled={!canManage} />
              <Input label="Telefoon" value={editing.phone ?? ''} onChange={(e) => setEditing({ ...editing, phone: e.target.value })} disabled={!canManage} />
            </div>
            <Select label="Prijslijst" value={editing.price_list_id ? String(editing.price_list_id) : ''} onChange={(v) => setEditing({ ...editing, price_list_id: v ? Number(v) : null })} options={[{ value: '', label: 'Standaard' }, ...(lists.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} disabled={!canManage} />
            <div className="cv-grid cv-grid--form">
              <Input label="BTW-nummer" value={editing.vat ?? ''} onChange={(e) => setEditing({ ...editing, vat: e.target.value })} disabled={!canManage} />
              <Input label="WordPress user-id's (portaal)" description="Komma-gescheiden; deze gebruikers krijgen de klantenrol" value={editing.userIdsText ?? ''} onChange={(e) => setEditing({ ...editing, userIdsText: e.target.value })} disabled={!canManage} />
            </div>
            <Textarea label="Facturatieadres" rows={2} value={editing.billing_address ?? ''} onChange={(e) => setEditing({ ...editing, billing_address: e.target.value })} disabled={!canManage} />
            <Textarea label="Notities voor dispatch" rows={3} value={editing.notes ?? ''} onChange={(e) => setEditing({ ...editing, notes: e.target.value })} disabled={!canManage} />
            <Checkbox label="Actief" checked={editing.active ?? true} onChange={(e) => setEditing({ ...editing, active: e.target.checked })} disabled={!canManage} />
          </Stack>
        )}
      </Drawer>
    </div>
  );
}

export function CouriersPage() {
  const api = useApi();
  const ctx = useAppContext();
  const toast = useToast();
  const list = useLoad(() => api.couriers(), []);
  const [editing, setEditing] = useState<Partial<Courier> | null>(null);
  const [busy, setBusy] = useState(false);
  const canManage = ctx.user.role === 'admin';

  const save = async () => {
    if (!editing) return;
    setBusy(true);
    try {
      editing.id ? await api.courierUpdate(editing.id, editing) : await api.courierCreate(editing);
      toast.success('Koerier opgeslagen');
      setEditing(null);
      list.reload();
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const columns: TableColumn<CourierRow>[] = [
    { key: 'name', header: 'Koerier', render: (c) => <button type="button" className="cv-link-btn cv-strong" onClick={() => setEditing({ ...c })}>{c.name}</button> },
    { key: 'hub', header: 'Hub', width: '100px' },
    { key: 'phone', header: 'Telefoon', width: '160px' },
    { key: 'user_id', header: 'WP user', width: '100px', render: (c) => <span className={c.user_id ? '' : 'cv-muted'}>{c.user_id ?? 'geen app'}</span> },
    { key: 'active', header: 'Status', width: '100px', render: (c) => <Badge variant={c.active ? 'success' : 'neutral'}>{c.active ? 'Actief' : 'Inactief'}</Badge> },
  ];
  return (
    <div>
      <PageHeader title="Koeriers" description="Een koerier met een WordPress-account krijgt automatisch de koeriersrol en ziet zijn stops in de app." actions={canManage && <Button leftIcon={<Plus size={16} />} onClick={() => setEditing({ name: '', hub: ctx.hubs[0]?.code ?? '', phone: '', active: true, user_id: null })}>Nieuwe koerier</Button>} />
      <div className="cv-table-wrap">{list.data ? <Table<CourierRow> columns={columns} rows={list.data as CourierRow[]} rowKey={(c) => c.id} bordered={false} responsive="auto" /> : <div style={{ padding: 16 }}><Skeleton variant="rect" height={200} /></div>}</div>
      <Drawer open={editing !== null} onClose={() => setEditing(null)} title={editing?.id ? editing.name : 'Nieuwe koerier'} width={480} footer={canManage ? <><Button loading={busy} onClick={save}>Opslaan</Button><Button variant="ghost" onClick={() => setEditing(null)}>Sluiten</Button></> : undefined}>
        {editing && (
          <Stack gap={12}>
            <Input label="Naam" required value={editing.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} disabled={!canManage} />
            <Select label="Hub" value={editing.hub ?? ''} onChange={(v) => setEditing({ ...editing, hub: v })} options={ctx.hubs.map((h) => ({ value: h.code, label: h.name }))} disabled={!canManage} />
            <Input label="Telefoon" value={editing.phone ?? ''} onChange={(e) => setEditing({ ...editing, phone: e.target.value })} disabled={!canManage} />
            <Input label="WordPress user-id" type="number" description="Koppelt de koeriersapp aan dit account" value={editing.user_id ?? ''} onChange={(e) => setEditing({ ...editing, user_id: e.target.value ? Number(e.target.value) : null })} disabled={!canManage} />
            <Checkbox label="Actief (kan toegewezen worden)" checked={editing.active ?? true} onChange={(e) => setEditing({ ...editing, active: e.target.checked })} disabled={!canManage} />
          </Stack>
        )}
      </Drawer>
    </div>
  );
}

export function PricesPage() {
  const api = useApi();
  const toast = useToast();
  const lists = useLoad(() => api.priceLists(), []);
  const [draft, setDraft] = useState<PriceList[]>([]);
  const [newName, setNewName] = useState('');
  const [busy, setBusy] = useState<number | null>(null);
  useEffect(() => {
    if (lists.data) setDraft(lists.data.map((l) => ({ ...l, rules: l.rules.map((r) => ({ ...r, surcharges: { ...r.surcharges } })) })));
  }, [lists.data]);

  const cents = (v: string) => Math.round(parseFloat(v.replace(',', '.') || '0') * 100);
  const euro = (c: number | undefined) => ((c ?? 0) / 100).toFixed(2);
  const setRule = (li: number, service: ServiceCode, patch: Partial<PriceList['rules'][number]>) =>
    setDraft(draft.map((l, i) => (i === li ? { ...l, rules: l.rules.map((r) => (r.service === service ? { ...r, ...patch } : r)) } : l)));
  const setSur = (li: number, service: ServiceCode, key: string, v: string) => {
    const rule = draft[li]!.rules.find((r) => r.service === service)!;
    setRule(li, service, { surcharges: { ...rule.surcharges, [key]: cents(v) } });
  };
  const save = async (li: number) => {
    const l = draft[li]!;
    setBusy(l.id);
    try {
      await api.priceListUpdate(l.id, { name: l.name, rules: l.rules });
      toast.success(`${l.name} opgeslagen`);
      lists.reload();
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const SUR = [['m', 'M'], ['l', 'L'], ['xl', 'XL'], ['fragile', 'Breekbaar'], ['cooled', 'Gekoeld']] as const;
  return (
    <div>
      <PageHeader title="Prijslijsten" description="Basisprijs per dienst, toeslag per extra pakket, toeslagen per gewichtsklasse en optie. De prijs wordt vastgelegd op de zending bij boeking." actions={<Inline><Input placeholder="Naam nieuwe lijst" size="sm" value={newName} onChange={(e) => setNewName(e.target.value)} /><Button size="sm" disabled={!newName.trim()} onClick={async () => { await api.priceListCreate(newName); setNewName(''); lists.reload(); }}>Toevoegen</Button></Inline>} />
      <Stack gap={20}>
        {draft.map((l, li) => (
          <div key={l.id} className="cv-panel">
            <Inline justify="space-between">
              <Inline><Input size="sm" value={l.name} onChange={(e) => setDraft(draft.map((x, i) => (i === li ? { ...x, name: e.target.value } : x)))} />{l.is_default && <Badge variant="accent">Standaard</Badge>}</Inline>
              <Button size="sm" loading={busy === l.id} onClick={() => save(li)}>Opslaan</Button>
            </Inline>
            <div style={{ overflowX: 'auto', marginTop: 12 }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <thead><tr style={{ textAlign: 'left', color: 'var(--color-fg-muted)' }}><th style={{ padding: 6 }}>Dienst</th><th style={{ padding: 6 }}>Basis</th><th style={{ padding: 6 }}>Extra pakket</th>{SUR.map(([k, lab]) => <th key={k} style={{ padding: 6 }}>{lab}</th>)}<th style={{ padding: 6 }}>Voorbeeld 1× S</th></tr></thead>
                <tbody>
                  {l.rules.map((r) => (
                    <tr key={r.service}>
                      <td style={{ padding: 6 }} className="cv-strong">{SERVICE_LABEL[r.service]}</td>
                      <td style={{ padding: 6 }}><Input size="sm" inputMode="decimal" defaultValue={euro(r.base_cents)} onBlur={(e) => setRule(li, r.service, { base_cents: cents(e.target.value) })} /></td>
                      <td style={{ padding: 6 }}><Input size="sm" inputMode="decimal" defaultValue={euro(r.extra_parcel_cents)} onBlur={(e) => setRule(li, r.service, { extra_parcel_cents: cents(e.target.value) })} /></td>
                      {SUR.map(([k]) => <td key={k} style={{ padding: 6 }}><Input size="sm" inputMode="decimal" defaultValue={euro(r.surcharges[k])} onBlur={(e) => setSur(li, r.service, k, e.target.value)} /></td>)}
                      <td style={{ padding: 6 }}>{formatMoney(r.base_cents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ))}
      </Stack>
    </div>
  );
}

export function SettingsPage() {
  const api = useApi();
  const toast = useToast();
  const settings = useLoad(() => api.settings(), []);
  const [draft, setDraft] = useState<Record<string, unknown> | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (settings.data) setDraft(JSON.parse(JSON.stringify(settings.data)));
  }, [settings.data]);
  if (!draft) return <Skeleton variant="rect" height={300} />;
  type Hub = { code: string; name: string; city: string; postcodes: Array<string | [number, number]>; cutoff_sameday: string };
  const hubs = (draft.hubs as Hub[]) ?? [];
  const rangeText = (h: Hub) => h.postcodes.map((r) => (Array.isArray(r) ? `${r[0]}-${r[1]}` : r)).join(', ');
  const setHub = (i: number, patch: Partial<Hub>) => setDraft({ ...draft, hubs: hubs.map((h, j) => (j === i ? { ...h, ...patch } : h)) });
  const save = async () => {
    setBusy(true);
    try {
      await api.settingsUpdate({ ...draft, hubs: hubs.map((h) => ({ ...h, postcodes: rangeText(h).split(',').map((s) => s.trim()).filter(Boolean) })) });
      toast.success('Instellingen opgeslagen');
      settings.reload();
    } catch (e) {
      toast.error('Niet gelukt', errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <div style={{ maxWidth: 900 }}>
      <PageHeader title="Instellingen" description="Hubs met bediende postcodes en cut-off voor same day, meldingsadres voor dispatch, pagina van de track & trace." actions={<Button loading={busy} onClick={save}>Opslaan</Button>} />
      <Stack gap={16}>
        <div className="cv-panel">
          <div className="cv-strong" style={{ marginBottom: 12 }}>Hubs en zones</div>
          <Stack gap={10}>
            {hubs.map((h, i) => (
              <div key={i} className="cv-grid cv-grid--form" style={{ gridTemplateColumns: '80px 1fr 1fr 1.4fr 100px auto', alignItems: 'end' }}>
                <Input label="Code" value={h.code} onChange={(e) => setHub(i, { code: e.target.value.toUpperCase() })} />
                <Input label="Naam" value={h.name} onChange={(e) => setHub(i, { name: e.target.value })} />
                <Input label="Stad" value={h.city} onChange={(e) => setHub(i, { city: e.target.value })} />
                <Input label="Postcodes (van-tot, komma)" defaultValue={rangeText(h)} onBlur={(e) => setHub(i, { postcodes: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })} />
                <Input label="Cut-off" type="time" value={h.cutoff_sameday} onChange={(e) => setHub(i, { cutoff_sameday: e.target.value })} />
                <Button variant="ghost" size="sm" onClick={() => setDraft({ ...draft, hubs: hubs.filter((_, j) => j !== i) })}>Verwijder</Button>
              </div>
            ))}
            <div><Button variant="secondary" size="sm" onClick={() => setDraft({ ...draft, hubs: [...hubs, { code: '', name: '', city: '', postcodes: [], cutoff_sameday: '16:00' }] })}>Hub toevoegen</Button></div>
          </Stack>
        </div>
        <div className="cv-panel">
          <div className="cv-grid cv-grid--form">
            <Input label="Meldingen naar (dispatch)" type="email" value={String(draft.notify_email ?? '')} onChange={(e) => setDraft({ ...draft, notify_email: e.target.value })} description="Nieuwe websiteaanvragen en mislukte leveringen" />
            <Input label="Pad van de track & trace pagina" value={String(draft.tracking_page ?? '/volg-je-zending/')} onChange={(e) => setDraft({ ...draft, tracking_page: e.target.value })} description="Pagina met de shortcode [cargovelo_tracking]" />
          </div>
        </div>
      </Stack>
    </div>
  );
}
