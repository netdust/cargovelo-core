import type { ShipmentStatus, ServiceCode, Channel } from './types';

export const STATUS_ORDER: ShipmentStatus[] = [
  'requested',
  'confirmed',
  'assigned',
  'picked_up',
  'in_transit',
  'delivered',
  'failed',
  'cancelled',
];

export const STATUS_LABEL: Record<ShipmentStatus, string> = {
  draft: 'Ontwerp',
  requested: 'Aangevraagd',
  confirmed: 'Bevestigd',
  assigned: 'Toegewezen',
  picked_up: 'Opgehaald',
  in_transit: 'Onderweg',
  delivered: 'Geleverd',
  failed: 'Mislukt',
  cancelled: 'Geannuleerd',
};

export type BadgeTone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';

export const STATUS_TONE: Record<ShipmentStatus, BadgeTone> = {
  draft: 'neutral',
  requested: 'warning',
  confirmed: 'info',
  assigned: 'info',
  picked_up: 'accent',
  in_transit: 'accent',
  delivered: 'success',
  failed: 'danger',
  cancelled: 'neutral',
};

export const STATUS_DOT: Record<ShipmentStatus, string> = {
  draft: 'var(--color-fg-subtle)',
  requested: 'var(--color-warning-solid)',
  confirmed: 'var(--color-info-solid)',
  assigned: 'var(--color-info-solid)',
  picked_up: 'var(--color-chart-2)',
  in_transit: 'var(--color-chart-1)',
  delivered: 'var(--color-success-solid)',
  failed: 'var(--color-danger-solid)',
  cancelled: 'var(--color-fg-subtle)',
};

// Same table as ShipmentStatus.php::TRANSITIONS. Client-side only for hiding buttons;
// the server is the authority.
export const TRANSITIONS: Record<ShipmentStatus, ShipmentStatus[]> = {
  draft: ['requested', 'cancelled'],
  requested: ['confirmed', 'cancelled'],
  confirmed: ['assigned', 'cancelled'],
  assigned: ['picked_up', 'confirmed', 'cancelled'],
  picked_up: ['in_transit', 'delivered', 'failed'],
  in_transit: ['delivered', 'failed'],
  delivered: [],
  failed: ['confirmed', 'cancelled'],
  cancelled: [],
};

export const OPEN_STATUSES: ShipmentStatus[] = ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit'];
export const CLOSED_STATUSES: ShipmentStatus[] = ['delivered', 'failed', 'cancelled'];

export function canTransition(from: ShipmentStatus, to: ShipmentStatus): boolean {
  return TRANSITIONS[from].includes(to);
}

export function isOpen(status: ShipmentStatus): boolean {
  return OPEN_STATUSES.includes(status);
}

export const SERVICE_LABEL: Record<ServiceCode, string> = {
  express: 'Express',
  sameday: 'Same day',
  nextday: 'Next day',
  scheduled: 'Gepland',
};

export const CHANNEL_LABEL: Record<Channel, string> = {
  web: 'Website',
  portal: 'Portaal',
  import: 'Import',
  api: 'API',
  phone: 'Telefoon',
  recurring: 'Vaste ronde',
};

export const FAIL_REASONS: Array<{ value: string; label: string }> = [
  { value: 'not_home', label: 'Niemand aanwezig' },
  { value: 'closed', label: 'Adres gesloten' },
  { value: 'wrong_address', label: 'Verkeerd adres' },
  { value: 'refused', label: 'Geweigerd' },
  { value: 'damaged', label: 'Beschadigd' },
  { value: 'not_ready', label: 'Zending niet klaar bij ophaling' },
  { value: 'other', label: 'Andere' },
];

export const WEIGHT_LABEL: Record<string, string> = {
  xs: 'XS · tot 2 kg',
  s: 'S · tot 5 kg',
  m: 'M · tot 15 kg',
  l: 'L · tot 30 kg',
  xl: 'XL · tot 60 kg',
};
