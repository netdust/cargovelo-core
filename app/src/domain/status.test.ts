import { describe, expect, it } from 'vitest';
import { CLOSED_STATUSES, OPEN_STATUSES, STATUS_LABEL, TRANSITIONS, canTransition, isOpen } from './status';
import type { ShipmentStatus } from './types';

describe('status table (mirror of ShipmentStatus.php)', () => {
  it('matches the PHP transition table exactly', () => {
    expect(TRANSITIONS).toEqual({
      draft: ['requested', 'cancelled'],
      requested: ['confirmed', 'cancelled'],
      confirmed: ['assigned', 'cancelled'],
      assigned: ['picked_up', 'confirmed', 'cancelled'],
      picked_up: ['in_transit', 'delivered', 'failed'],
      in_transit: ['delivered', 'failed'],
      delivered: [],
      failed: ['confirmed', 'cancelled'],
      cancelled: [],
    });
  });
  it('every status has a label and is open xor closed (draft aside)', () => {
    for (const s of Object.keys(TRANSITIONS) as ShipmentStatus[]) {
      expect(STATUS_LABEL[s]).toBeTruthy();
      if (s !== 'draft') expect(OPEN_STATUSES.includes(s) !== CLOSED_STATUSES.includes(s)).toBe(true);
    }
  });
  it('helpers agree with the table', () => {
    expect(canTransition('assigned', 'delivered')).toBe(false);
    expect(canTransition('in_transit', 'delivered')).toBe(true);
    expect(isOpen('picked_up')).toBe(true);
    expect(isOpen('cancelled')).toBe(false);
  });
});
