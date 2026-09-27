import { ApiError, type Api, type CargoveloConfig, type StatusPayload } from './client';
import type { Channel, ShipmentFilter, ShipmentInput, ShipmentStatus } from '../domain/types';

/**
 * REST client over wp.apiFetch (cookie auth + X-WP-Nonce handled by WordPress). Falls back to
 * fetch() with the localized nonce when the wp-api-fetch script is absent.
 */
export class WpApi implements Api {
  private readonly ns: string;

  constructor(private readonly config: CargoveloConfig) {
    this.ns = '/' + config.restNamespace.replace(/^\/|\/$/g, '');
  }

  private async call<T>(method: string, path: string, data?: unknown): Promise<T> {
    const fullPath = this.ns + path;
    try {
      const apiFetch = window.wp?.apiFetch;
      if (apiFetch) {
        return (await apiFetch({ path: fullPath, method, data })) as T;
      }
      const root = (this.config.restRoot ?? '/wp-json/').replace(/\/$/, '');
      const url = root + fullPath.replace(/^\/cargovelo\/v1/, '');
      const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.config.nonce ?? '' },
        credentials: 'same-origin',
        body: data === undefined ? undefined : JSON.stringify(data),
      });
      const body = (await res.json().catch(() => ({}))) as Record<string, unknown>;
      if (!res.ok) throw body;
      return body as T;
    } catch (err) {
      throw toApiError(err);
    }
  }

  private query(filter: Record<string, unknown>): string {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(filter)) {
      if (v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0)) continue;
      p.set(k, Array.isArray(v) ? v.join(',') : String(v));
    }
    const s = p.toString();
    return s ? `?${s}` : '';
  }

  private fileUrl(path: string): string {
    const root = (this.config.restRoot ?? '/wp-json/cargovelo/v1/').replace(/\/$/, '');
    const nonce = this.config.nonce ?? window.wp?.apiFetch?.nonceMiddleware?.nonce ?? '';
    return `${root}${path}${path.includes('?') ? '&' : '?'}_wpnonce=${encodeURIComponent(nonce)}`;
  }

  me = () => this.call<Awaited<ReturnType<Api['me']>>>('GET', '/me');

  opsList = (f: ShipmentFilter) => this.call<Awaited<ReturnType<Api['opsList']>>>('GET', '/ops/shipments' + this.query(f as Record<string, unknown>));
  opsGet = (id: number) => this.call<Awaited<ReturnType<Api['opsGet']>>>('GET', `/ops/shipments/${id}`);
  opsCreate = (input: ShipmentInput, channel: Channel) => this.call<Awaited<ReturnType<Api['opsCreate']>>>('POST', '/ops/shipments', { ...input, channel });
  opsUpdate = (id: number, patch: object) => this.call<Awaited<ReturnType<Api['opsUpdate']>>>('PATCH', `/ops/shipments/${id}`, patch);
  opsStatus = (id: number, status: ShipmentStatus, payload: StatusPayload = {}) => this.call<Awaited<ReturnType<Api['opsStatus']>>>('POST', `/ops/shipments/${id}/status`, { status, ...payload });
  opsAssign = (id: number, courier_id: number | null, stop_sequence?: number | null) => this.call<Awaited<ReturnType<Api['opsAssign']>>>('POST', `/ops/shipments/${id}/assign`, { courier_id, stop_sequence });
  opsPrice = (id: number, price_cents: number, reason: string) => this.call<Awaited<ReturnType<Api['opsPrice']>>>('POST', `/ops/shipments/${id}/price`, { price_cents, reason });
  opsNote = (id: number, note: string, visible_to_customer: boolean) => this.call<Awaited<ReturnType<Api['opsNote']>>>('POST', `/ops/shipments/${id}/note`, { note, visible_to_customer });
  opsQuote = (input: Partial<ShipmentInput>) => this.call<Awaited<ReturnType<Api['opsQuote']>>>('POST', '/ops/quote', input);
  opsCounts = (hub?: string) => this.call<Awaited<ReturnType<Api['opsCounts']>>>('GET', '/ops/counts' + this.query({ hub }));
  opsDashboard = (hub?: string) => this.call<Awaited<ReturnType<Api['opsDashboard']>>>('GET', '/ops/dashboard' + this.query({ hub }));
  opsExportUrl = (from: string, to: string, customer_id?: number) => this.fileUrl('/ops/export' + this.query({ from, to, customer_id }));
  couriers = async (hub?: string) => (await this.call<{ items: Awaited<ReturnType<Api['couriers']>> }>('GET', '/ops/couriers' + this.query({ hub }))).items;
  courierCreate = (data: object) => this.call<Awaited<ReturnType<Api['courierCreate']>>>('POST', '/ops/couriers', data);
  courierUpdate = (id: number, data: object) => this.call<Awaited<ReturnType<Api['courierUpdate']>>>('PATCH', `/ops/couriers/${id}`, data);
  courierReorder = async (id: number, shipment_ids: number[]) => {
    await this.call('POST', `/ops/couriers/${id}/reorder`, { shipment_ids });
  };
  customers = async (search?: string) => (await this.call<{ items: Awaited<ReturnType<Api['customers']>> }>('GET', '/ops/customers' + this.query({ search }))).items;
  customerCreate = (data: object) => this.call<Awaited<ReturnType<Api['customerCreate']>>>('POST', '/ops/customers', data);
  customerUpdate = (id: number, data: object) => this.call<Awaited<ReturnType<Api['customerUpdate']>>>('PATCH', `/ops/customers/${id}`, data);
  customerAddresses = async (id: number) => (await this.call<{ items: Awaited<ReturnType<Api['customerAddresses']>> }>('GET', `/ops/customers/${id}/addresses`)).items;
  priceLists = async () => (await this.call<{ items: Awaited<ReturnType<Api['priceLists']>> }>('GET', '/ops/price-lists')).items;
  priceListCreate = (name: string) => this.call<Awaited<ReturnType<Api['priceListCreate']>>>('POST', '/ops/price-lists', { name });
  priceListUpdate = (id: number, patch: object) => this.call<Awaited<ReturnType<Api['priceListUpdate']>>>('PUT', `/ops/price-lists/${id}`, patch);
  settings = () => this.call<Record<string, unknown>>('GET', '/ops/settings');
  settingsUpdate = (patch: object) => this.call<Record<string, unknown>>('PUT', '/ops/settings', patch);

  portalList = (f: ShipmentFilter) => this.call<Awaited<ReturnType<Api['portalList']>>>('GET', '/portal/shipments' + this.query(f as Record<string, unknown>));
  portalGet = (id: number) => this.call<Awaited<ReturnType<Api['portalGet']>>>('GET', `/portal/shipments/${id}`);
  portalCreate = (input: ShipmentInput) => this.call<Awaited<ReturnType<Api['portalCreate']>>>('POST', '/portal/shipments', input);
  portalCancel = (id: number, reason: string) => this.call<Awaited<ReturnType<Api['portalCancel']>>>('POST', `/portal/shipments/${id}/cancel`, { reason });
  portalNote = (id: number, note: string) => this.call<Awaited<ReturnType<Api['portalNote']>>>('POST', `/portal/shipments/${id}/note`, { note });
  portalQuote = (input: Partial<ShipmentInput>) => this.call<Awaited<ReturnType<Api['portalQuote']>>>('POST', '/portal/quote', input);
  portalCounts = () => this.call<Awaited<ReturnType<Api['portalCounts']>>>('GET', '/portal/counts');
  addresses = async () => (await this.call<{ items: Awaited<ReturnType<Api['addresses']>> }>('GET', '/portal/addresses')).items;
  addressCreate = (entry: object) => this.call<Awaited<ReturnType<Api['addressCreate']>>>('POST', '/portal/addresses', entry);
  addressDelete = async (id: number) => {
    await this.call('DELETE', `/portal/addresses/${id}`);
  };
  portalImport = (csv: string) => this.call<Awaited<ReturnType<Api['portalImport']>>>('POST', '/portal/import', { csv });
  portalImportTemplateUrl = () => this.fileUrl('/portal/import/template');

  courierStops = async (date?: string) => (await this.call<{ items: Awaited<ReturnType<Api['courierStops']>> }>('GET', '/courier/stops' + this.query({ date }))).items;
  courierStop = (id: number) => this.call<Awaited<ReturnType<Api['courierStop']>>>('GET', `/courier/stops/${id}`);
  courierStatus = (id: number, status: ShipmentStatus, payload: StatusPayload = {}) => this.call<Awaited<ReturnType<Api['courierStatus']>>>('POST', `/courier/stops/${id}/status`, { status, ...payload });
  courierNote = (id: number, note: string) => this.call<Awaited<ReturnType<Api['courierNote']>>>('POST', `/courier/stops/${id}/note`, { note });

  publicQuote = (input: Partial<ShipmentInput>) => this.call<Awaited<ReturnType<Api['publicQuote']>>>('POST', '/public/quote', input);
  publicBooking = (input: object) => this.call<Awaited<ReturnType<Api['publicBooking']>>>('POST', '/public/bookings', input);
  tracking = (token: string) => this.call<Awaited<ReturnType<Api['tracking']>>>('GET', `/public/tracking/${token}`);
  trackingInstructions = (token: string, instructions: string) => this.call<Awaited<ReturnType<Api['trackingInstructions']>>>('POST', `/public/tracking/${token}/instructions`, { instructions });

  podUrl = (scope: 'ops' | 'portal', id: number, kind: 'photo' | 'signature') => this.fileUrl(`/${scope}/shipments/${id}/pod/${kind}`);
}

function toApiError(err: unknown): ApiError {
  if (err instanceof ApiError) return err;
  const e = (err ?? {}) as { code?: string; message?: string; data?: { status?: number; field?: string } };
  const status = e.data?.status ?? 0;
  const message =
    e.message ??
    (status === 0 ? 'Geen verbinding met de server. Controleer je internetverbinding.' : 'Er ging iets mis.');
  return new ApiError(e.code ?? 'unknown', message, status, e.data?.field);
}
