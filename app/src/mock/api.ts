import { ApiError, type Api, type StatusPayload } from '../api/client';
import type { AddressBookEntry, AppContext, Channel, Courier, Customer, Parcel, PriceList, Quote, Shipment, ShipmentEvent, ShipmentFilter, ShipmentInput, ShipmentStatus, TrackingView } from '../domain/types';
import { OPEN_STATUSES, SERVICE_LABEL, TRANSITIONS } from '../domain/status';
import { toLocalIso, todayIso } from '../lib/format';
import { ADDRESS_BOOK, COURIERS, CUSTOMERS, HUBS, PRICE_LISTS, SERVICES, buildShipments, mockContext } from './data';

const delay = (ms = 120) => new Promise((r) => setTimeout(r, ms));

/**
 * In-memory implementation with the same rules as ShipmentService.php: status table, role scoping,
 * pricing, cut-offs. Used by `npm run dev`, screenshots and the UI smoke tests.
 */
export class MockApi implements Api {
  private shipments: Shipment[];
  private events: ShipmentEvent[];
  private customerRows = CUSTOMERS.map((c) => ({ ...c }));
  private courierRows = COURIERS.map((c) => ({ ...c }));
  private lists = PRICE_LISTS.map((l) => ({ ...l, rules: l.rules.map((r) => ({ ...r })) }));
  private addressBook = ADDRESS_BOOK.map((a) => ({ ...a }));
  private settingsData: Record<string, unknown> = { hubs: HUBS, services: SERVICES, notify_email: 'dispatch@cargovelo.be', tracking_page: '/volg-je-zending/' };
  private nextId: number;
  private nextEventId: number;

  constructor(private readonly context: AppContext) {
    const built = buildShipments();
    this.shipments = built.shipments;
    this.events = built.events;
    this.nextId = this.shipments.length + 1;
    this.nextEventId = this.events.length + 1;
    // Methods are handed around as callbacks (quote={api.x}); make them safe to detach.
    for (const key of Object.getOwnPropertyNames(MockApi.prototype)) {
      const v = (this as unknown as Record<string, unknown>)[key];
      if (key !== 'constructor' && typeof v === 'function') (this as unknown as Record<string, unknown>)[key] = (v as (...a: unknown[]) => unknown).bind(this);
    }
  }

  private get actor() {
    return this.context.user;
  }

  private hubFor(postcode: string): string | null {
    const pc = parseInt(postcode, 10);
    for (const h of HUBS) {
      for (const range of h.postcodes) {
        const [a, b] = range.split('-').map(Number);
        if (pc >= a! && pc <= b!) return h.code;
      }
    }
    return null;
  }

  private quoteFor(service: ShipmentInput['service'], parcels: Parcel[], listId: number | null): Quote {
    const list = this.lists.find((l) => l.id === listId) ?? this.lists.find((l) => l.is_default)!;
    const rule: { base_cents: number; extra_parcel_cents: number; surcharges: Partial<Record<Parcel['weight_class'] | 'fragile' | 'cooled', number>> } = list.rules.find((r) => r.service === service) ?? { base_cents: 0, extra_parcel_cents: 0, surcharges: {} };
    const breakdown = [{ label: SERVICE_LABEL[service], cents: rule.base_cents }];
    const count = parcels.reduce((n, p) => n + p.count, 0);
    if (count > 1) breakdown.push({ label: `${count - 1} extra ${count - 1 === 1 ? 'pakket' : 'pakketten'}`, cents: (count - 1) * rule.extra_parcel_cents });
    const order = ['xs', 's', 'm', 'l', 'xl'];
    const heaviest = parcels.reduce((h, p) => (order.indexOf(p.weight_class) > order.indexOf(h) ? p.weight_class : h), 'xs' as Parcel['weight_class']);
    const w = rule.surcharges[heaviest] ?? 0;
    if (w) breakdown.push({ label: `Gewichtsklasse ${heaviest.toUpperCase()}`, cents: w });
    if (parcels.some((p) => p.fragile) && rule.surcharges.fragile) breakdown.push({ label: 'Breekbaar', cents: rule.surcharges.fragile });
    if (parcels.some((p) => p.cooled) && rule.surcharges.cooled) breakdown.push({ label: 'Gekoeld transport', cents: rule.surcharges.cooled });
    return { service, price_cents: breakdown.reduce((n, b) => n + b.cents, 0), breakdown, currency: 'EUR' };
  }

  private validate(input: Partial<ShipmentInput>): void {
    if (!input.service) throw new ApiError('invalid_service', 'Kies een dienst.', 400, 'service');
    for (const [key, label] of [['pickup', 'Ophaaladres'], ['delivery', 'Leveradres']] as const) {
      const a = input[key];
      const missing = ['name', 'street', 'number', 'postcode', 'city'].filter((f) => !(a as Record<string, string> | undefined)?.[f]?.trim());
      if (missing.length) throw new ApiError('invalid_address', `${label}: verplichte velden ontbreken (${missing.join(', ')}).`, 400, key);
      if (!/^\d{4}$/.test(a!.postcode.trim())) throw new ApiError('invalid_postcode', `${label}: ongeldige Belgische postcode.`, 400, key);
      if (!this.hubFor(a!.postcode)) throw new ApiError('outside_zone', `${label} ligt buiten onze zones (Gent, Antwerpen, Brussel, Mechelen, Leuven).`, 400, key);
    }
    if (!input.parcels?.length) throw new ApiError('invalid_parcels', 'Minstens één pakket is vereist.', 400, 'parcels');
    if (input.service === 'scheduled' && !input.pickup_window?.start) throw new ApiError('window_required', 'Kies een datum en tijdstip voor de ophaling.', 400, 'pickup_window');
    if (input.service === 'sameday' && new Date().getHours() >= 16) throw new ApiError('cutoff_passed', 'De cut-off voor same day (16:00) is voorbij. Kies next day of een geplande levering.', 400, 'service');
  }

  private create(input: ShipmentInput, channel: Channel, actor: AppContext['user']): Shipment {
    this.validate(input);
    let customer: Customer | undefined;
    if (actor.role === 'customer') customer = this.customerRows.find((c) => c.id === actor.customer_id);
    else if (input.customer_id) customer = this.customerRows.find((c) => c.id === input.customer_id);
    else {
      const email = (input.contact_email ?? input.pickup.email ?? '').toLowerCase();
      if (!email) throw new ApiError('contact_required', 'Een geldig e-mailadres is verplicht voor de bevestiging.', 400, 'contact_email');
      customer = this.customerRows.find((c) => c.email === email);
      if (!customer) {
        customer = { id: this.customerRows.length + 1, name: input.contact_company || input.pickup.company || input.contact_name || input.pickup.name, type: 'occasional', email, phone: input.pickup.phone ?? '', hub: this.hubFor(input.pickup.postcode) ?? '', price_list_id: null, vat: '', billing_address: '', notes: '', active: true };
        this.customerRows.push(customer);
      }
    }
    const quote = this.quoteFor(input.service, input.parcels, customer?.price_list_id ?? null);
    const status: ShipmentStatus = actor.role === 'admin' || actor.role === 'dispatcher' || customer?.type === 'account' ? 'confirmed' : 'requested';
    const now = new Date();
    const start = input.pickup_window?.start ?? toLocalIso(now);
    const end = new Date(start);
    if (input.service === 'express') end.setMinutes(end.getMinutes() + 90);
    else if (input.service === 'scheduled') end.setHours(end.getHours() + 4);
    else end.setHours(18, 0, 0, 0);
    if (input.service === 'nextday') end.setDate(end.getDate() + 1);
    const id = this.nextId++;
    const d = new Date();
    const s: Shipment = {
      id,
      reference: `CV-${String(d.getFullYear()).slice(2)}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}-${String(1000 + id).slice(1)}`,
      customer_id: customer?.id ?? null,
      customer_name: customer?.name ?? input.pickup.company ?? input.pickup.name,
      contact_email: input.contact_email ?? customer?.email ?? input.pickup.email ?? '',
      service: input.service,
      hub: this.hubFor(input.pickup.postcode)!,
      status,
      exception: null,
      pickup: { ...input.pickup },
      delivery: { ...input.delivery },
      pickup_window: { start: input.service === 'nextday' ? toLocalIso(new Date(end.getFullYear(), end.getMonth(), end.getDate(), 9)) : start, end: input.pickup_window?.end ?? null },
      delivery_window: { start: null, end: toLocalIso(end) },
      parcels: input.parcels,
      price_cents: quote.price_cents,
      price_override_reason: null,
      courier_id: null,
      courier_name: null,
      stop_sequence: null,
      channel,
      tracking_token: Array.from({ length: 24 }, () => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join(''),
      remarks: input.remarks ?? '',
      created_at: toLocalIso(now),
      updated_at: toLocalIso(now),
    };
    this.shipments.unshift(s);
    this.log(s, 'status', actor, {}, null, status);
    return s;
  }

  private log(s: Shipment, type: ShipmentEvent['type'], actor: AppContext['user'], payload: Record<string, unknown>, from: ShipmentStatus | null = null, to: ShipmentStatus | null = null) {
    this.events.push({ id: this.nextEventId++, shipment_id: s.id, type, from_status: from, to_status: to, actor_id: actor.id, actor_name: actor.name, actor_role: actor.role, payload, created_at: toLocalIso(new Date()) });
    s.updated_at = toLocalIso(new Date());
  }

  private withEvents(s: Shipment, actor = this.actor): Shipment {
    const events = this.events.filter((e) => e.shipment_id === s.id && (actor.role !== 'customer' || e.type !== 'note' || e.payload.visible_to_customer));
    return { ...s, events };
  }

  private find(id: number, actor = this.actor): Shipment {
    const s = this.shipments.find((x) => x.id === id);
    const ok = s && (actor.role === 'admin' || actor.role === 'dispatcher' || (actor.role === 'customer' && s.customer_id === actor.customer_id) || (actor.role === 'courier' && s.courier_id === actor.courier_id));
    if (!ok) throw new ApiError('not_found', 'Zending niet gevonden.', 404);
    return s;
  }

  private transition(s: Shipment, to: ShipmentStatus, actor: AppContext['user'], payload: StatusPayload): Shipment {
    if (actor.role === 'courier' && !['picked_up', 'in_transit', 'delivered', 'failed'].includes(to)) throw new ApiError('forbidden', 'Een koerier kan deze status niet zetten.', 403);
    if (actor.role === 'customer' && !(to === 'cancelled' && ['requested', 'confirmed'].includes(s.status))) throw new ApiError('forbidden', 'Annuleren kan enkel zolang de zending niet toegewezen is. Neem contact op met dispatch.', 403);
    if (!TRANSITIONS[s.status].includes(to)) throw new ApiError('invalid_transition', `Van "${s.status}" naar "${to}" is niet mogelijk.`, 409);
    const from = s.status;
    s.status = to;
    if (to === 'failed') s.exception = payload.reason ?? 'other';
    if (to === 'confirmed' || to === 'delivered' || to === 'cancelled') s.exception = null;
    if (to === 'confirmed' && from === 'assigned') {
      s.courier_id = null;
      s.courier_name = null;
      s.stop_sequence = null;
    }
    if (to === 'delivered') this.log(s, 'pod', actor, { receiver_name: payload.receiver_name ?? '', note: payload.note ?? '', delivered_at: toLocalIso(new Date()), photo_path: payload.photo_base64 ? 'mock' : undefined, photo_data: payload.photo_base64, signature_data: payload.signature_base64 });
    this.log(s, 'status', actor, { reason: payload.reason, note: payload.note }, from, to);
    return this.withEvents(s, actor);
  }

  private list(filter: ShipmentFilter, actor: AppContext['user']) {
    let rows = this.shipments.filter((s) => actor.role !== 'customer' || s.customer_id === actor.customer_id);
    if (filter.status?.length) rows = rows.filter((s) => filter.status!.includes(s.status));
    if (filter.service?.length) rows = rows.filter((s) => filter.service!.includes(s.service));
    if (filter.hub) rows = rows.filter((s) => s.hub === filter.hub);
    if (filter.customer_id) rows = rows.filter((s) => s.customer_id === filter.customer_id);
    if (filter.courier_id) rows = rows.filter((s) => s.courier_id === filter.courier_id);
    if (filter.exception !== undefined) rows = rows.filter((s) => (s.exception !== null) === filter.exception);
    if (filter.date_from) rows = rows.filter((s) => (s.pickup_window.start ?? s.created_at).slice(0, 10) >= filter.date_from!);
    if (filter.date_to) rows = rows.filter((s) => (s.pickup_window.start ?? s.created_at).slice(0, 10) <= filter.date_to!);
    if (filter.search) {
      const q = filter.search.toLowerCase();
      rows = rows.filter((s) => [s.reference, s.customer_name, s.pickup.name, s.pickup.street, s.delivery.name, s.delivery.street, s.delivery.city].join(' ').toLowerCase().includes(q));
    }
    rows.sort((a, b) => (a.pickup_window.start ?? a.created_at).localeCompare(b.pickup_window.start ?? b.created_at) * (filter.order === 'asc' ? 1 : -1));
    const per_page = filter.per_page ?? 25;
    const page = filter.page ?? 1;
    return { items: rows.slice((page - 1) * per_page, page * per_page), total: rows.length, page, per_page };
  }

  private counts(rows: Shipment[]) {
    const by_status = Object.fromEntries(['draft', 'requested', 'confirmed', 'assigned', 'picked_up', 'in_transit', 'delivered', 'failed', 'cancelled'].map((s) => [s, rows.filter((r) => r.status === s).length])) as Record<ShipmentStatus, number>;
    const today = todayIso();
    return { by_status, exceptions: rows.filter((r) => r.exception && OPEN_STATUSES.includes(r.status)).length, today: rows.filter((r) => (r.pickup_window.start ?? r.created_at).startsWith(today)).length };
  }

  async me() {
    await delay(50);
    return this.context;
  }

  // ---- ops
  async opsList(filter: ShipmentFilter) {
    await delay();
    return this.list(filter, this.actor);
  }
  async opsGet(id: number) {
    await delay(80);
    return this.withEvents(this.find(id));
  }
  async opsCreate(input: ShipmentInput, channel: Channel) {
    await delay();
    return this.withEvents(this.create(input, channel, this.actor));
  }
  async opsUpdate(id: number, patch: Partial<ShipmentInput> & { customer_id?: number }) {
    await delay();
    const s = this.find(id);
    if (!OPEN_STATUSES.includes(s.status)) throw new ApiError('closed', 'Een afgesloten zending kan niet bewerkt worden.', 409);
    if (patch.pickup) s.pickup = { ...patch.pickup };
    if (patch.delivery) s.delivery = { ...patch.delivery };
    if (patch.parcels) s.parcels = patch.parcels;
    if (patch.service) s.service = patch.service;
    if (patch.remarks !== undefined) s.remarks = patch.remarks;
    if (patch.pickup_window) s.pickup_window = patch.pickup_window;
    if (patch.customer_id) {
      const c = this.customerRows.find((x) => x.id === patch.customer_id);
      if (c) {
        s.customer_id = c.id;
        s.customer_name = c.name;
      }
    }
    if (!s.price_override_reason) s.price_cents = this.quoteFor(s.service, s.parcels, this.customerRows.find((c) => c.id === s.customer_id)?.price_list_id ?? null).price_cents;
    this.log(s, 'edit', this.actor, { fields: Object.keys(patch) });
    return this.withEvents(s);
  }
  async opsStatus(id: number, status: ShipmentStatus, payload: StatusPayload = {}) {
    await delay();
    return this.transition(this.find(id), status, this.actor, payload);
  }
  async opsAssign(id: number, courierId: number | null, stopSequence: number | null = null) {
    await delay();
    const s = this.find(id);
    if (courierId === null) return this.transition(s, 'confirmed', this.actor, {});
    const c = this.courierRows.find((x) => x.id === courierId);
    if (!c || !c.active) throw new ApiError('invalid_courier', 'Koerier niet gevonden of inactief.', 400);
    if (!['confirmed', 'assigned', 'picked_up', 'in_transit'].includes(s.status)) throw new ApiError('invalid_transition', `Een zending met status "${s.status}" kan niet toegewezen worden.`, 409);
    const from = s.status;
    if (s.status === 'confirmed') s.status = 'assigned';
    s.courier_id = c.id;
    s.courier_name = c.name;
    s.stop_sequence = stopSequence ?? (this.shipments.filter((x) => x.courier_id === c.id && OPEN_STATUSES.includes(x.status)).length || 1);
    this.log(s, 'assignment', this.actor, { courier_id: c.id, courier_name: c.name }, from, s.status);
    return this.withEvents(s);
  }
  async opsPrice(id: number, cents: number, reason: string) {
    await delay();
    const s = this.find(id);
    if (!reason.trim() || cents < 0) throw new ApiError('invalid_price', 'Prijs en reden zijn verplicht.', 400);
    this.log(s, 'price', this.actor, { from_cents: s.price_cents, to_cents: cents, reason });
    s.price_cents = cents;
    s.price_override_reason = reason;
    return this.withEvents(s);
  }
  async opsNote(id: number, note: string, visible: boolean) {
    await delay();
    const s = this.find(id);
    this.log(s, 'note', this.actor, { note, visible_to_customer: visible });
    return this.withEvents(s);
  }
  async opsQuote(input: Partial<ShipmentInput>) {
    await delay(60);
    if (!input.service || !input.parcels?.length) throw new ApiError('invalid_parcels', 'Minstens één pakket is vereist.', 400);
    const listId = input.customer_id ? this.customerRows.find((c) => c.id === input.customer_id)?.price_list_id ?? null : null;
    return this.quoteFor(input.service, input.parcels, listId);
  }
  async opsCounts(hub?: string) {
    await delay(40);
    return this.counts(this.shipments.filter((s) => !hub || s.hub === hub));
  }
  async opsDashboard(hub?: string) {
    await delay();
    const rows = this.shipments.filter((s) => !hub || s.hub === hub);
    const today = todayIso();
    const todays = rows.filter((s) => (s.pickup_window.start ?? s.created_at).startsWith(today));
    const days = Array.from({ length: 7 }, (_, i) => {
      const d = new Date();
      d.setDate(d.getDate() - 6 + i);
      const key = toLocalIso(d).slice(0, 10);
      const dayRows = rows.filter((s) => (s.pickup_window.start ?? s.created_at).startsWith(key));
      return { label: d.toLocaleDateString('nl-BE', { weekday: 'short', day: '2-digit', month: '2-digit' }), value: dayRows.length, value2: dayRows.filter((s) => s.status === 'failed').length };
    });
    const by = (key: 'service' | 'hub') => Object.entries(rows.reduce<Record<string, number>>((acc, s) => ({ ...acc, [s[key]]: (acc[s[key]] ?? 0) + 1 }), {})).map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value);
    const delivered = rows.filter((s) => s.status === 'delivered' && s.delivery_window.end);
    const onTime = delivered.filter((s) => s.updated_at <= s.delivery_window.end!).length;
    return {
      today_total: todays.length,
      today_delivered: todays.filter((s) => s.status === 'delivered').length,
      today_open: todays.filter((s) => OPEN_STATUSES.includes(s.status)).length,
      on_time_rate: delivered.length ? onTime / delivered.length : 1,
      volume_7d: days,
      by_service: by('service'),
      by_hub: by('hub'),
    };
  }
  opsExportUrl(from: string, to: string, customerId?: number) {
    const rows = this.shipments.filter((s) => s.status === 'delivered' && s.updated_at.slice(0, 10) >= from && s.updated_at.slice(0, 10) <= to && (!customerId || s.customer_id === customerId));
    const csv = ['referentie;klant;geleverd_op;dienst;prijs_eur', ...rows.map((s) => `${s.reference};${s.customer_name};${s.updated_at};${s.service};${((s.price_cents ?? 0) / 100).toFixed(2).replace('.', ',')}`)].join('\n');
    return 'data:text/csv;charset=utf-8,' + encodeURIComponent('﻿' + csv);
  }
  async couriers(hub?: string) {
    await delay(40);
    return this.courierRows.filter((c) => !hub || c.hub === hub);
  }
  async courierCreate(data: Partial<Courier>) {
    await delay();
    const c: Courier = { id: this.courierRows.length + 1, user_id: data.user_id ?? null, name: data.name ?? '', hub: data.hub ?? '', phone: data.phone ?? '', active: data.active ?? true };
    this.courierRows.push(c);
    return c;
  }
  async courierUpdate(id: number, data: Partial<Courier>) {
    await delay();
    const c = this.courierRows.find((x) => x.id === id)!;
    Object.assign(c, data);
    return c;
  }
  async courierReorder(courierId: number, ids: number[]) {
    await delay();
    ids.forEach((id, i) => {
      const s = this.shipments.find((x) => x.id === id && x.courier_id === courierId);
      if (s) s.stop_sequence = i + 1;
    });
  }
  async customers(search?: string) {
    await delay(40);
    const q = (search ?? '').toLowerCase();
    return this.customerRows.filter((c) => !q || c.name.toLowerCase().includes(q) || c.email.includes(q));
  }
  async customerCreate(data: Partial<Customer>) {
    await delay();
    const c: Customer = { id: this.customerRows.length + 1, name: data.name ?? '', type: data.type ?? 'occasional', email: data.email ?? '', phone: data.phone ?? '', hub: data.hub ?? '', price_list_id: data.price_list_id ?? null, vat: data.vat ?? '', billing_address: data.billing_address ?? '', notes: data.notes ?? '', active: true };
    this.customerRows.push(c);
    return c;
  }
  async customerUpdate(id: number, data: Partial<Customer>) {
    await delay();
    const c = this.customerRows.find((x) => x.id === id)!;
    Object.assign(c, data);
    return c;
  }
  async customerAddresses(id: number) {
    await delay(40);
    return this.addressBook.filter((a) => a.customer_id === id);
  }
  async priceLists() {
    await delay(40);
    return this.lists;
  }
  async priceListCreate(name: string) {
    await delay();
    const id = this.lists.length + 1;
    const l: PriceList = { id, name, is_default: false, rules: PRICE_LISTS[0]!.rules.map((r, i) => ({ ...r, id: 100 + id * 10 + i, price_list_id: id })) };
    this.lists.push(l);
    return l;
  }
  async priceListUpdate(id: number, patch: { name?: string; rules?: PriceList['rules'] }) {
    await delay();
    const l = this.lists.find((x) => x.id === id)!;
    if (patch.name) l.name = patch.name;
    if (patch.rules) for (const r of patch.rules) Object.assign(l.rules.find((x) => x.service === r.service) ?? {}, r);
    return l;
  }
  async settings() {
    await delay(40);
    return this.settingsData;
  }
  async settingsUpdate(patch: Record<string, unknown>) {
    await delay();
    this.settingsData = { ...this.settingsData, ...patch };
    return this.settingsData;
  }

  // ---- portal
  private get portalActor(): AppContext['user'] {
    return this.actor.role === 'customer' ? this.actor : { ...this.actor, role: 'customer', customer_id: this.actor.customer_id ?? 1 };
  }
  async portalList(filter: ShipmentFilter) {
    await delay();
    return this.list(filter, this.portalActor);
  }
  async portalGet(id: number) {
    await delay(80);
    return this.withEvents(this.find(id, this.portalActor), this.portalActor);
  }
  async portalCreate(input: ShipmentInput) {
    await delay();
    return this.withEvents(this.create(input, 'portal', this.portalActor), this.portalActor);
  }
  async portalCancel(id: number, reason: string) {
    await delay();
    return this.transition(this.find(id, this.portalActor), 'cancelled', this.portalActor, { reason });
  }
  async portalNote(id: number, note: string) {
    await delay();
    const s = this.find(id, this.portalActor);
    this.log(s, 'note', this.portalActor, { note, visible_to_customer: true });
    return this.withEvents(s, this.portalActor);
  }
  async portalQuote(input: Partial<ShipmentInput>) {
    await delay(60);
    if (!input.service || !input.parcels?.length) throw new ApiError('invalid_parcels', 'Minstens één pakket is vereist.', 400);
    return this.quoteFor(input.service, input.parcels, this.customerRows.find((c) => c.id === this.portalActor.customer_id)?.price_list_id ?? null);
  }
  async portalCounts() {
    await delay(40);
    return this.counts(this.shipments.filter((s) => s.customer_id === this.portalActor.customer_id));
  }
  async addresses() {
    await delay(40);
    return this.addressBook.filter((a) => a.customer_id === this.portalActor.customer_id);
  }
  async addressCreate(entry: Omit<AddressBookEntry, 'id' | 'customer_id'>) {
    await delay();
    const a: AddressBookEntry = { ...entry, id: this.addressBook.length + 1, customer_id: this.portalActor.customer_id! };
    this.addressBook.push(a);
    return a;
  }
  async addressDelete(id: number) {
    await delay();
    this.addressBook = this.addressBook.filter((a) => a.id !== id);
  }
  async portalImport(csv: string) {
    await delay(300);
    const lines = csv.trim().split(/\r?\n/);
    const sep = (lines[0] ?? '').includes(';') ? ';' : ',';
    const header = (lines[0] ?? '').split(sep).map((h) => h.trim().toLowerCase());
    const rows = lines.slice(1).map((line, i) => {
      const cells = line.split(sep);
      const r = Object.fromEntries(header.map((h, j) => [h, (cells[j] ?? '').trim()]));
      try {
        const s = this.create({
          service: r.service as ShipmentInput['service'],
          pickup: { name: r.pickup_name ?? '', company: r.pickup_company, street: r.pickup_street ?? '', number: r.pickup_number ?? '', postcode: r.pickup_postcode ?? '', city: r.pickup_city ?? '' },
          delivery: { name: r.delivery_name ?? '', company: r.delivery_company, street: r.delivery_street ?? '', number: r.delivery_number ?? '', postcode: r.delivery_postcode ?? '', city: r.delivery_city ?? '' },
          parcels: [{ count: parseInt(r.parcels ?? '1', 10) || 1, weight_class: (r.weight_class as Parcel['weight_class']) || 's', fragile: ['1', 'ja', 'yes'].includes((r.fragile ?? '').toLowerCase()), cooled: ['1', 'ja', 'yes'].includes((r.cooled ?? '').toLowerCase()) }],
          pickup_window: r.pickup_date ? { start: `${r.pickup_date}T${r.pickup_time_from || '09:00'}:00`, end: r.pickup_time_to ? `${r.pickup_date}T${r.pickup_time_to}:00` : null } : undefined,
          remarks: r.remarks,
        }, 'import', this.portalActor);
        return { line: i + 2, ok: true, reference: s.reference };
      } catch (e) {
        return { line: i + 2, ok: false, error: (e as Error).message };
      }
    });
    return { created: rows.filter((r) => r.ok).length, failed: rows.filter((r) => !r.ok).length, rows };
  }
  portalImportTemplateUrl() {
    return 'data:text/csv;charset=utf-8,' + encodeURIComponent('service;pickup_name;pickup_company;pickup_street;pickup_number;pickup_box;pickup_postcode;pickup_city;pickup_phone;pickup_email;pickup_instructions;delivery_name;delivery_company;delivery_street;delivery_number;delivery_box;delivery_postcode;delivery_city;delivery_phone;delivery_email;delivery_instructions;parcels;weight_class;fragile;cooled;pickup_date;pickup_time_from;pickup_time_to;remarks\nsameday;Labo Noord;UZ Gent;Corneel Heymanslaan;10;;9000;Gent;09 332 21 11;;Ingang C;Dr. Peeters;AZ Sint-Lucas;Groenebriel;1;;9000;Gent;;;Onthaal;2;s;ja;ja;;;;Stalen koel houden\n');
  }

  // ---- courier
  async courierStops(date?: string) {
    await delay();
    const day = date ?? todayIso();
    return this.shipments
      .filter((s) => s.courier_id === this.actor.courier_id && (OPEN_STATUSES.includes(s.status) || s.updated_at.startsWith(day)) && (s.pickup_window.start ?? s.created_at).slice(0, 10) <= day)
      .sort((a, b) => (a.stop_sequence ?? 999) - (b.stop_sequence ?? 999) || (a.pickup_window.start ?? '').localeCompare(b.pickup_window.start ?? ''));
  }
  async courierStop(id: number) {
    await delay(60);
    return this.withEvents(this.find(id));
  }
  async courierStatus(id: number, status: ShipmentStatus, payload: StatusPayload = {}) {
    await delay();
    return this.transition(this.find(id), status, this.actor, payload);
  }
  async courierNote(id: number, note: string) {
    await delay();
    const s = this.find(id);
    this.log(s, 'note', this.actor, { note, visible_to_customer: false });
    return this.withEvents(s);
  }

  // ---- public
  async publicQuote(input: Partial<ShipmentInput>) {
    await delay(60);
    if (!input.service || !input.parcels?.length) throw new ApiError('invalid_parcels', 'Minstens één pakket is vereist.', 400);
    return this.quoteFor(input.service, input.parcels, null);
  }
  async publicBooking(input: ShipmentInput & { website?: string }) {
    await delay(200);
    const s = this.create(input, 'web', { id: 0, name: 'Website', email: '', role: 'guest', customer_id: null, courier_id: null, hub: null });
    return { reference: s.reference, status: s.status, price_cents: s.price_cents, tracking_token: s.tracking_token, tracking_url: `?view=tracking&t=${s.tracking_token}` };
  }
  async tracking(token: string): Promise<TrackingView> {
    await delay();
    const s = this.shipments.find((x) => x.tracking_token === token);
    if (!s) throw new ApiError('not_found', 'Zending niet gevonden.', 404);
    const pod = [...this.events].reverse().find((e) => e.shipment_id === s.id && e.type === 'pod');
    return {
      reference: s.reference,
      status: s.status,
      exception: s.exception,
      service: s.service,
      delivery_city: s.delivery.city,
      delivery_window: s.delivery_window,
      events: this.events.filter((e) => e.shipment_id === s.id && (e.type === 'status' || (e.type === 'note' && e.actor_role === 'guest'))).map((e) => ({ type: e.type, to_status: e.to_status, created_at: e.created_at, note: e.payload.note as string | undefined })),
      can_edit_instructions: OPEN_STATUSES.includes(s.status),
      instructions: s.delivery.instructions ?? '',
      pod: pod ? { receiver_name: pod.payload.receiver_name as string, delivered_at: pod.created_at, has_photo: !!pod.payload.photo_path } : undefined,
    };
  }
  async trackingInstructions(token: string, instructions: string) {
    await delay();
    const s = this.shipments.find((x) => x.tracking_token === token);
    if (!s) throw new ApiError('not_found', 'Zending niet gevonden.', 404);
    if (!OPEN_STATUSES.includes(s.status)) throw new ApiError('closed', 'Deze zending is afgesloten.', 409);
    s.delivery.instructions = instructions;
    this.log(s, 'note', { id: 0, name: 'Ontvanger', email: '', role: 'guest', customer_id: null, courier_id: null, hub: null }, { note: `Leverinstructies aangepast: ${instructions}`, visible_to_customer: true });
    return this.tracking(token);
  }

  podUrl(_scope: 'ops' | 'portal', id: number, kind: 'photo' | 'signature') {
    const pod = [...this.events].reverse().find((e) => e.shipment_id === id && e.type === 'pod');
    return (pod?.payload[`${kind}_data`] as string | undefined) ?? '';
  }
}

export function createMockApi(role: string): MockApi {
  return new MockApi(mockContext(role as AppContext['user']['role']));
}
