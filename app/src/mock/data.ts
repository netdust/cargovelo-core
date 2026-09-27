import type { Address, AddressBookEntry, AppContext, Courier, Customer, Hub, PriceList, ServiceDefinition, Shipment, ShipmentEvent, ShipmentStatus } from '../domain/types';
import { toLocalIso } from '../lib/format';

// Deterministic pseudo-random so screenshots and tests are stable.
let seed = 42;
export function rand(): number {
  seed = (seed * 1664525 + 1013904223) % 4294967296;
  return seed / 4294967296;
}
const pick = <T,>(arr: T[]): T => arr[Math.floor(rand() * arr.length)]!;

export const HUBS: Hub[] = [
  { code: 'GNT', name: 'Hub Gent', city: 'Gent', postcodes: ['9000-9052'], cutoff_sameday: '16:00' },
  { code: 'ANR', name: 'Hub Antwerpen', city: 'Antwerpen', postcodes: ['2000-2660'], cutoff_sameday: '16:00' },
  { code: 'BRU', name: 'Hub Brussel', city: 'Brussel', postcodes: ['1000-1210'], cutoff_sameday: '16:00' },
  { code: 'MEC', name: 'Hub Mechelen', city: 'Mechelen', postcodes: ['2800-2812'], cutoff_sameday: '15:00' },
  { code: 'LEU', name: 'Hub Leuven', city: 'Leuven', postcodes: ['3000-3012'], cutoff_sameday: '15:00' },
];

export const SERVICES: ServiceDefinition[] = [
  { code: 'express', name: 'Express', description: 'Ophaling binnen het uur, levering binnen 30 min na ophaling.', sla_minutes: 90 },
  { code: 'sameday', name: 'Same day', description: 'Geboekt voor de cut-off, vandaag geleverd.', sla_minutes: null },
  { code: 'nextday', name: 'Next day', description: 'Volgende werkdag geleverd.', sla_minutes: null },
  { code: 'scheduled', name: 'Gepland', description: 'Kies zelf dag en tijdsvenster.', sla_minutes: null },
];

export const CUSTOMERS: Customer[] = [
  { id: 1, name: 'Labo Noord', type: 'account', email: 'planning@labonoord.be', phone: '09 332 21 11', hub: 'GNT', price_list_id: 2, vat: 'BE0123456789', billing_address: 'Corneel Heymanslaan 10, 9000 Gent', notes: 'Stalen elke werkdag 8u en 13u', active: true },
  { id: 2, name: 'Drukkerij Verstraete', type: 'account', email: 'orders@verstraete.be', phone: '03 225 45 67', hub: 'ANR', price_list_id: 2, vat: 'BE0987654321', billing_address: 'Kloosterstraat 12, 2000 Antwerpen', notes: '', active: true },
  { id: 3, name: 'Bloemen Bea', type: 'occasional', email: 'bea@bloemenbea.be', phone: '0470 12 34 56', hub: 'GNT', price_list_id: null, vat: '', billing_address: '', notes: '', active: true },
  { id: 4, name: 'Apotheek Centrum', type: 'account', email: 'info@apotheekcentrum.be', phone: '02 511 22 33', hub: 'BRU', price_list_id: 2, vat: 'BE0555666777', billing_address: 'Grote Markt 1, 1000 Brussel', notes: 'Gekoeld', active: true },
  { id: 5, name: 'Webshop Sokkenfabriek', type: 'account', email: 'fulfilment@sokkenfabriek.be', phone: '', hub: 'MEC', price_list_id: null, vat: 'BE0111222333', billing_address: 'Industrieweg 5, 2800 Mechelen', notes: 'Dagelijkse hub-levering', active: true },
  { id: 6, name: 'Restaurant De Kade', type: 'occasional', email: 'hallo@dekade.be', phone: '016 20 30 40', hub: 'LEU', price_list_id: null, vat: '', billing_address: '', notes: '', active: true },
];

export const COURIERS: Courier[] = [
  { id: 1, user_id: 101, name: 'Kris Vermeulen', hub: 'GNT', phone: '0478 11 22 33', active: true },
  { id: 2, user_id: 102, name: 'Aïcha Benali', hub: 'GNT', phone: '0478 22 33 44', active: true },
  { id: 3, user_id: 103, name: 'Tom De Wilde', hub: 'ANR', phone: '0478 33 44 55', active: true },
  { id: 4, user_id: 104, name: 'Lena Jacobs', hub: 'BRU', phone: '0478 44 55 66', active: true },
  { id: 5, user_id: 105, name: 'Sam Peeters', hub: 'MEC', phone: '0478 55 66 77', active: true },
  { id: 6, user_id: null, name: 'Jonas Claes', hub: 'LEU', phone: '', active: false },
];

export const PRICE_LISTS: PriceList[] = [
  {
    id: 1, name: 'Standaard', is_default: true,
    rules: [
      { id: 1, price_list_id: 1, service: 'express', base_cents: 1490, extra_parcel_cents: 300, surcharges: { m: 250, l: 500, xl: 1000, fragile: 200, cooled: 400 } },
      { id: 2, price_list_id: 1, service: 'sameday', base_cents: 990, extra_parcel_cents: 250, surcharges: { m: 250, l: 500, xl: 1000, fragile: 200, cooled: 400 } },
      { id: 3, price_list_id: 1, service: 'nextday', base_cents: 790, extra_parcel_cents: 200, surcharges: { m: 250, l: 500, xl: 1000, fragile: 200, cooled: 400 } },
      { id: 4, price_list_id: 1, service: 'scheduled', base_cents: 890, extra_parcel_cents: 200, surcharges: { m: 250, l: 500, xl: 1000, fragile: 200, cooled: 400 } },
    ],
  },
  {
    id: 2, name: 'Contract labo/apotheek', is_default: false,
    rules: [
      { id: 5, price_list_id: 2, service: 'express', base_cents: 1200, extra_parcel_cents: 200, surcharges: { cooled: 250 } },
      { id: 6, price_list_id: 2, service: 'sameday', base_cents: 750, extra_parcel_cents: 150, surcharges: { cooled: 250 } },
      { id: 7, price_list_id: 2, service: 'nextday', base_cents: 650, extra_parcel_cents: 150, surcharges: { cooled: 250 } },
      { id: 8, price_list_id: 2, service: 'scheduled', base_cents: 700, extra_parcel_cents: 150, surcharges: { cooled: 250 } },
    ],
  },
];

const CITY: Record<string, { postcode: string; city: string; streets: string[] }> = {
  GNT: { postcode: '9000', city: 'Gent', streets: ['Korenmarkt', 'Veldstraat', 'Coupure Links', 'Sint-Pietersnieuwstraat', 'Dampoortstraat'] },
  ANR: { postcode: '2000', city: 'Antwerpen', streets: ['Meir', 'Kloosterstraat', 'Nationalestraat', 'Lange Nieuwstraat', 'Amerikalei'] },
  BRU: { postcode: '1000', city: 'Brussel', streets: ['Grote Markt', 'Anspachlaan', 'Dansaertstraat', 'Louizalaan', 'Vlaamsesteenweg'] },
  MEC: { postcode: '2800', city: 'Mechelen', streets: ['Bruul', 'IJzerenleen', 'Onze-Lieve-Vrouwestraat', 'Industrieweg'] },
  LEU: { postcode: '3000', city: 'Leuven', streets: ['Bondgenotenlaan', 'Tiensestraat', 'Diestsestraat', 'Naamsestraat'] },
};
const NAMES = ['Jan Peeters', 'Marie Claes', 'Dr. Ahmed', 'Sofie Maes', 'Onthaal', 'Els Van Damme', 'Pieter Jansens', 'Nour El Amrani', 'Magazijn', 'Lotte Wouters'];

function address(hub: string, name?: string, company?: string): Address {
  const c = CITY[hub]!;
  return {
    name: name ?? pick(NAMES),
    company: company ?? '',
    street: pick(c.streets),
    number: String(1 + Math.floor(rand() * 120)),
    postcode: c.postcode,
    city: c.city,
    phone: rand() > 0.4 ? `04${Math.floor(70 + rand() * 29)} ${Math.floor(10 + rand() * 89)} ${Math.floor(10 + rand() * 89)} ${Math.floor(10 + rand() * 89)}` : '',
    email: rand() > 0.5 ? 'ontvanger@example.be' : '',
    instructions: rand() > 0.7 ? 'Bel aan bij de buren als niemand thuis is' : '',
  };
}

function ref(d: Date, n: number): string {
  const y = String(d.getFullYear()).slice(2);
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `CV-${y}${m}${day}-${String(n).padStart(4, '0')}`;
}

export function buildShipments(): { shipments: Shipment[]; events: ShipmentEvent[] } {
  const shipments: Shipment[] = [];
  const events: ShipmentEvent[] = [];
  const now = new Date();
  let id = 1;
  let eventId = 1;

  const push = (dayOffset: number, status: ShipmentStatus, opts: Partial<Shipment> = {}) => {
    const customer = opts.customer_id ? CUSTOMERS.find((c) => c.id === opts.customer_id)! : pick(CUSTOMERS);
    const hub = opts.hub ?? customer.hub;
    const day = new Date(now);
    day.setDate(now.getDate() + dayOffset);
    const hour = 8 + Math.floor(rand() * 9);
    day.setHours(hour, [0, 15, 30, 45][Math.floor(rand() * 4)]!, 0, 0);
    const service = opts.service ?? pick(['express', 'sameday', 'sameday', 'nextday', 'scheduled'] as const);
    const courierPool = COURIERS.filter((c) => c.hub === hub && c.active);
    const courier = ['assigned', 'picked_up', 'in_transit', 'delivered', 'failed'].includes(status) && courierPool.length ? pick(courierPool) : null;
    const created = new Date(day);
    created.setHours(day.getHours() - 2);
    const deliveryEnd = new Date(day);
    deliveryEnd.setHours(service === 'express' ? day.getHours() + 1 : 18, service === 'express' ? day.getMinutes() + 30 : 0);
    const updated = new Date(day);
    if (status === 'delivered' || status === 'failed') updated.setMinutes(day.getMinutes() + 40 + Math.floor(rand() * 200));
    const parcels = [{ count: 1 + Math.floor(rand() * 3), weight_class: pick(['xs', 's', 's', 'm', 'l'] as const), fragile: rand() > 0.7, cooled: customer.id === 1 || customer.id === 4 ? rand() > 0.3 : false }];
    const base = { express: 1490, sameday: 990, nextday: 790, scheduled: 890 }[service];
    const price = base + (parcels[0]!.count - 1) * 250 + (parcels[0]!.fragile ? 200 : 0) + (parcels[0]!.cooled ? 400 : 0);
    const s: Shipment = {
      id,
      reference: ref(created, id),
      customer_id: customer.id,
      customer_name: customer.name,
      contact_email: customer.email,
      service,
      hub,
      status,
      exception: status === 'failed' ? pick(['not_home', 'closed', 'wrong_address']) : opts.exception ?? null,
      pickup: address(hub, customer.name.split(' ')[0], customer.name),
      delivery: address(hub),
      pickup_window: { start: toLocalIso(day), end: null },
      delivery_window: { start: null, end: toLocalIso(deliveryEnd) },
      parcels,
      price_cents: price,
      price_override_reason: null,
      courier_id: courier?.id ?? null,
      courier_name: courier?.name ?? null,
      stop_sequence: courier ? 1 + Math.floor(rand() * 8) : null,
      channel: customer.type === 'account' ? pick(['portal', 'portal', 'import', 'api']) : pick(['web', 'web', 'phone']),
      tracking_token: Array.from({ length: 24 }, () => '0123456789abcdef'[Math.floor(rand() * 16)]).join(''),
      remarks: rand() > 0.75 ? pick(['Fragiel, rechtop houden', 'Stalen koel houden', 'Ophalen aan de achterdeur', 'Graag voor 12u']) : '',
      created_at: toLocalIso(created),
      updated_at: toLocalIso(updated),
      ...opts,
    };
    shipments.push(s);
    const chain: ShipmentStatus[] = ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit', 'delivered'];
    const idx = chain.indexOf(status === 'failed' ? 'in_transit' : status === 'cancelled' ? 'confirmed' : status);
    let t = new Date(created);
    let prev: ShipmentStatus | null = null;
    for (let i = 0; i <= idx; i++) {
      const st = chain[i]!;
      if (st === 'requested' && s.channel !== 'web') continue;
      events.push({ id: eventId++, shipment_id: id, type: 'status', from_status: prev, to_status: st, actor_id: 1, actor_name: st === 'requested' ? 'Website' : i >= 3 ? s.courier_name ?? 'Koerier' : 'Dispatch', actor_role: st === 'requested' ? 'guest' : i >= 3 ? 'courier' : 'dispatcher', payload: {}, created_at: toLocalIso(t) });
      prev = st;
      t = new Date(t.getTime() + 25 * 60000);
    }
    if (status === 'failed') events.push({ id: eventId++, shipment_id: id, type: 'status', from_status: 'in_transit', to_status: 'failed', actor_id: 1, actor_name: s.courier_name ?? 'Koerier', actor_role: 'courier', payload: { reason: s.exception }, created_at: s.updated_at });
    if (status === 'cancelled') events.push({ id: eventId++, shipment_id: id, type: 'status', from_status: 'confirmed', to_status: 'cancelled', actor_id: 1, actor_name: 'Dispatch', actor_role: 'dispatcher', payload: { reason: 'Klant annuleerde' }, created_at: s.updated_at });
    if (status === 'delivered') events.push({ id: eventId++, shipment_id: id, type: 'pod', from_status: null, to_status: null, actor_id: 1, actor_name: s.courier_name ?? 'Koerier', actor_role: 'courier', payload: { receiver_name: s.delivery.name, note: '', delivered_at: s.updated_at }, created_at: s.updated_at });
    id++;
  };

  // Past week: mostly delivered, a few failed/cancelled.
  for (let d = -7; d < 0; d++) {
    const n = 6 + Math.floor(rand() * 6);
    for (let i = 0; i < n; i++) push(d, rand() > 0.9 ? 'failed' : rand() > 0.96 ? 'cancelled' : 'delivered');
  }
  // Today: the live board.
  const today: ShipmentStatus[] = ['delivered', 'delivered', 'delivered', 'in_transit', 'in_transit', 'picked_up', 'assigned', 'assigned', 'assigned', 'confirmed', 'confirmed', 'requested', 'requested', 'failed'];
  today.forEach((st) => push(0, st));
  push(0, 'requested', { customer_id: 3, hub: 'GNT', service: 'express', channel: 'web' });
  push(0, 'confirmed', { customer_id: 1, hub: 'GNT', service: 'sameday', channel: 'import' });
  push(0, 'assigned', { customer_id: 1, hub: 'GNT', service: 'scheduled', courier_id: 1, courier_name: 'Kris Vermeulen' });
  push(0, 'assigned', { customer_id: 4, hub: 'BRU', service: 'sameday', courier_id: 4, courier_name: 'Lena Jacobs' });
  push(0, 'in_transit', { customer_id: 2, hub: 'ANR', service: 'express', courier_id: 3, courier_name: 'Tom De Wilde' });
  // Tomorrow and later.
  for (let d = 1; d <= 3; d++) {
    const n = 3 + Math.floor(rand() * 4);
    for (let i = 0; i < n; i++) push(d, rand() > 0.3 ? 'confirmed' : 'requested', { service: rand() > 0.5 ? 'scheduled' : 'nextday' });
  }
  return { shipments, events };
}

export const ADDRESS_BOOK: AddressBookEntry[] = [
  { id: 1, customer_id: 1, label: 'Labo hoofdgebouw', name: 'Onthaal labo', company: 'Labo Noord', street: 'Corneel Heymanslaan', number: '10', box: '', postcode: '9000', city: 'Gent', phone: '09 332 21 11', email: 'planning@labonoord.be', instructions: 'Ingang C, melden aan de balie' },
  { id: 2, customer_id: 1, label: 'AZ Sint-Lucas', name: 'Dr. Peeters', company: 'AZ Sint-Lucas', street: 'Groenebriel', number: '1', box: '', postcode: '9000', city: 'Gent', phone: '', email: '', instructions: 'Onthaal, koelkast links' },
  { id: 3, customer_id: 1, label: 'Huisartsenpraktijk Zuid', name: 'Dr. Maes', company: '', street: 'Sint-Pietersnieuwstraat', number: '45', box: '2', postcode: '9000', city: 'Gent', phone: '09 222 33 44', email: '', instructions: '' },
];

export function mockContext(role: AppContext['user']['role']): AppContext {
  const users: Record<string, AppContext['user']> = {
    admin: { id: 1, name: 'Willem-Frederik', email: 'wf@cargovelo.be', role: 'admin', customer_id: null, courier_id: null, hub: null },
    dispatcher: { id: 2, name: 'Dana Dispatch', email: 'dispatch@cargovelo.be', role: 'dispatcher', customer_id: null, courier_id: null, hub: 'GNT' },
    customer: { id: 20, name: 'Kim Planning', email: 'planning@labonoord.be', role: 'customer', customer_id: 1, courier_id: null, hub: null },
    courier: { id: 101, name: 'Kris Vermeulen', email: 'kris@cargovelo.be', role: 'courier', customer_id: null, courier_id: 1, hub: 'GNT' },
    guest: { id: 0, name: 'Bezoeker', email: '', role: 'guest', customer_id: null, courier_id: null, hub: null },
  };
  return {
    user: users[role] ?? users.guest!,
    rest: null,
    hubs: HUBS,
    services: SERVICES,
    urls: { logout: '#', site: '#' },
  };
}
