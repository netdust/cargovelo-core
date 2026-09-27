// Mirrors the REST payloads of cargovelo-core (cargovelo/v1). Keep in sync with
// cargovelo-core/Modules/Shipment/ShipmentRepository.php::toArray().

export type ShipmentStatus =
  | 'draft'
  | 'requested'
  | 'confirmed'
  | 'assigned'
  | 'picked_up'
  | 'in_transit'
  | 'delivered'
  | 'failed'
  | 'cancelled';

export type ServiceCode = 'express' | 'sameday' | 'nextday' | 'scheduled';

export type Channel = 'web' | 'portal' | 'import' | 'api' | 'phone' | 'recurring';

export type WeightClass = 'xs' | 's' | 'm' | 'l' | 'xl';

export type Role = 'admin' | 'dispatcher' | 'courier' | 'customer' | 'guest';

export interface Address {
  name: string;
  company?: string;
  street: string;
  number: string;
  box?: string;
  postcode: string;
  city: string;
  phone?: string;
  email?: string;
  instructions?: string;
}

export interface Parcel {
  count: number;
  weight_class: WeightClass;
  fragile: boolean;
  cooled: boolean;
}

export interface TimeWindow {
  start: string | null; // ISO 8601, local time (Europe/Brussels)
  end: string | null;
}

export interface ShipmentEvent {
  id: number;
  shipment_id: number;
  type: 'status' | 'note' | 'pod' | 'exception' | 'price' | 'assignment' | 'edit';
  from_status: ShipmentStatus | null;
  to_status: ShipmentStatus | null;
  actor_id: number | null;
  actor_name: string;
  actor_role: Role;
  payload: Record<string, unknown>;
  created_at: string;
}

export interface Shipment {
  id: number;
  reference: string;
  customer_id: number | null;
  customer_name: string;
  contact_email: string;
  service: ServiceCode;
  hub: string;
  status: ShipmentStatus;
  exception: string | null;
  pickup: Address;
  delivery: Address;
  pickup_window: TimeWindow;
  delivery_window: TimeWindow;
  parcels: Parcel[];
  price_cents: number | null;
  price_override_reason: string | null;
  courier_id: number | null;
  courier_name: string | null;
  stop_sequence: number | null;
  channel: Channel;
  tracking_token: string;
  remarks: string;
  created_at: string;
  updated_at: string;
  events?: ShipmentEvent[];
}

export interface ShipmentInput {
  customer_id?: number | null;
  service: ServiceCode;
  hub?: string;
  pickup: Address;
  delivery: Address;
  pickup_window?: TimeWindow;
  delivery_window?: TimeWindow;
  parcels: Parcel[];
  remarks?: string;
  channel?: Channel;
  // Guest booking only
  contact_email?: string;
  contact_name?: string;
  contact_company?: string;
}

export interface Customer {
  id: number;
  name: string;
  type: 'occasional' | 'account';
  email: string;
  phone: string;
  hub: string;
  price_list_id: number | null;
  vat: string;
  billing_address: string;
  notes: string;
  active: boolean;
}

export interface AddressBookEntry extends Address {
  id: number;
  customer_id: number;
  label: string;
}

export interface Courier {
  id: number;
  user_id: number | null;
  name: string;
  hub: string;
  phone: string;
  active: boolean;
}

export interface Hub {
  code: string;
  name: string;
  city: string;
  postcodes: string[];
  cutoff_sameday: string; // "16:00"
}

export interface ServiceDefinition {
  code: ServiceCode;
  name: string;
  description: string;
  sla_minutes: number | null;
}

export interface PriceRule {
  id: number;
  price_list_id: number;
  service: ServiceCode;
  base_cents: number;
  extra_parcel_cents: number;
  surcharges: Partial<Record<WeightClass | 'fragile' | 'cooled', number>>;
}

export interface PriceList {
  id: number;
  name: string;
  is_default: boolean;
  rules: PriceRule[];
}

export interface Quote {
  service: ServiceCode;
  price_cents: number;
  breakdown: Array<{ label: string; cents: number }>;
  currency: 'EUR';
}

export interface ShipmentFilter {
  status?: ShipmentStatus[];
  service?: ServiceCode[];
  hub?: string;
  customer_id?: number;
  courier_id?: number;
  date_from?: string; // YYYY-MM-DD, on pickup_window.start
  date_to?: string;
  search?: string;
  exception?: boolean;
  page?: number;
  per_page?: number;
  order?: 'asc' | 'desc';
}

export interface Paginated<T> {
  items: T[];
  total: number;
  page: number;
  per_page: number;
}

export interface StatusCounts {
  by_status: Record<ShipmentStatus, number>;
  exceptions: number;
  today: number;
}

export interface DashboardStats {
  today_total: number;
  today_delivered: number;
  today_open: number;
  on_time_rate: number; // 0..1
  volume_7d: Array<{ label: string; value: number; value2?: number }>;
  by_service: Array<{ label: string; value: number }>;
  by_hub: Array<{ label: string; value: number }>;
}

export interface PodInput {
  receiver_name?: string;
  photo_base64?: string; // data URL
  signature_base64?: string;
  note?: string;
  lat?: number;
  lng?: number;
}

export interface ImportRow {
  line: number;
  ok: boolean;
  error?: string;
  reference?: string;
}

export interface ImportResult {
  created: number;
  failed: number;
  rows: ImportRow[];
}

export interface TrackingView {
  reference: string;
  status: ShipmentStatus;
  exception: string | null;
  service: ServiceCode;
  delivery_city: string;
  delivery_window: TimeWindow;
  events: Array<{ to_status: ShipmentStatus | null; type: string; created_at: string; note?: string }>;
  can_edit_instructions: boolean;
  instructions: string;
  pod?: { receiver_name?: string; delivered_at: string; has_photo: boolean; photo_url?: string };
}

export interface AppContext {
  user: {
    id: number;
    name: string;
    email: string;
    role: Role;
    customer_id: number | null;
    courier_id: number | null;
    hub: string | null;
  };
  rest: { root: string; nonce: string } | null;
  hubs: Hub[];
  services: ServiceDefinition[];
  urls: { logout: string; site: string };
}
