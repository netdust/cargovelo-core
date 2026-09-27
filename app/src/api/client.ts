import type {
  AddressBookEntry,
  AppContext,
  Channel,
  Courier,
  Customer,
  DashboardStats,
  ImportResult,
  Paginated,
  PodInput,
  PriceList,
  Quote,
  Shipment,
  ShipmentFilter,
  ShipmentInput,
  ShipmentStatus,
  StatusCounts,
  TrackingView,
} from '../domain/types';

export class ApiError extends Error {
  constructor(
    public code: string,
    message: string,
    public status: number,
    public field?: string,
  ) {
    super(message);
  }
}

export interface StatusPayload extends PodInput {
  reason?: string;
  note?: string;
}

export interface PublicBookingResult {
  reference: string;
  status: ShipmentStatus;
  price_cents: number | null;
  tracking_token: string;
  tracking_url: string;
}

/** Every call the UI makes. WpApi maps to cargovelo/v1; MockApi keeps everything in memory. */
export interface Api {
  me(): Promise<AppContext>;

  // Ops desk
  opsList(filter: ShipmentFilter): Promise<Paginated<Shipment>>;
  opsGet(id: number): Promise<Shipment>;
  opsCreate(input: ShipmentInput, channel: Channel): Promise<Shipment>;
  opsUpdate(id: number, patch: Partial<ShipmentInput> & { customer_id?: number }): Promise<Shipment>;
  opsStatus(id: number, status: ShipmentStatus, payload?: StatusPayload): Promise<Shipment>;
  opsAssign(id: number, courierId: number | null, stopSequence?: number | null): Promise<Shipment>;
  opsPrice(id: number, cents: number, reason: string): Promise<Shipment>;
  opsNote(id: number, note: string, visibleToCustomer: boolean): Promise<Shipment>;
  opsQuote(input: Partial<ShipmentInput>): Promise<Quote>;
  opsCounts(hub?: string): Promise<StatusCounts>;
  opsDashboard(hub?: string): Promise<DashboardStats>;
  opsExportUrl(from: string, to: string, customerId?: number): string;
  couriers(hub?: string): Promise<Courier[]>;
  courierCreate(data: Partial<Courier>): Promise<Courier>;
  courierUpdate(id: number, data: Partial<Courier>): Promise<Courier>;
  courierReorder(id: number, shipmentIds: number[]): Promise<void>;
  customers(search?: string): Promise<Customer[]>;
  customerCreate(data: Partial<Customer> & { user_ids?: number[] }): Promise<Customer>;
  customerUpdate(id: number, data: Partial<Customer> & { user_ids?: number[] }): Promise<Customer>;
  customerAddresses(id: number): Promise<AddressBookEntry[]>;
  priceLists(): Promise<PriceList[]>;
  priceListCreate(name: string): Promise<PriceList>;
  priceListUpdate(id: number, patch: { name?: string; rules?: PriceList['rules'] }): Promise<PriceList>;
  settings(): Promise<Record<string, unknown>>;
  settingsUpdate(patch: Record<string, unknown>): Promise<Record<string, unknown>>;

  // Customer portal
  portalList(filter: ShipmentFilter): Promise<Paginated<Shipment>>;
  portalGet(id: number): Promise<Shipment>;
  portalCreate(input: ShipmentInput): Promise<Shipment>;
  portalCancel(id: number, reason: string): Promise<Shipment>;
  portalNote(id: number, note: string): Promise<Shipment>;
  portalQuote(input: Partial<ShipmentInput>): Promise<Quote>;
  portalCounts(): Promise<StatusCounts>;
  addresses(): Promise<AddressBookEntry[]>;
  addressCreate(entry: Omit<AddressBookEntry, 'id' | 'customer_id'>): Promise<AddressBookEntry>;
  addressDelete(id: number): Promise<void>;
  portalImport(csv: string): Promise<ImportResult>;
  portalImportTemplateUrl(): string;

  // Courier app
  courierStops(date?: string): Promise<Shipment[]>;
  courierStop(id: number): Promise<Shipment>;
  courierStatus(id: number, status: ShipmentStatus, payload?: StatusPayload): Promise<Shipment>;
  courierNote(id: number, note: string): Promise<Shipment>;

  // Public
  publicQuote(input: Partial<ShipmentInput>): Promise<Quote>;
  publicBooking(input: ShipmentInput & { website?: string }): Promise<PublicBookingResult>;
  tracking(token: string): Promise<TrackingView>;
  trackingInstructions(token: string, instructions: string): Promise<TrackingView>;

  /** Authenticated image URL for a proof-of-delivery file. */
  podUrl(scope: 'ops' | 'portal', id: number, kind: 'photo' | 'signature'): string;
}

export interface CargoveloConfig {
  mode: 'wp' | 'mock';
  restNamespace: string;
  restRoot?: string;
  nonce?: string;
  context?: AppContext;
  loginUrl?: string;
  view?: string;
}

declare global {
  interface Window {
    cargoveloConfig?: CargoveloConfig;
    wp?: { apiFetch?: ApiFetch };
  }
}

type ApiFetch = ((options: { path: string; method?: string; data?: unknown; parse?: boolean }) => Promise<unknown>) & {
  nonceMiddleware?: { nonce: string };
};

export function readConfig(): CargoveloConfig {
  return window.cargoveloConfig ?? { mode: 'mock', restNamespace: 'cargovelo/v1' };
}
