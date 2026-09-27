import { describe, expect, it } from 'vitest';
import { addressLine, formatMoney, formatWindow, initials, toLocalIso } from './format';

describe('format helpers', () => {
  it('formats money in nl-BE', () => {
    expect(formatMoney(1440).replace(/ /g, ' ')).toBe('€ 14,40');
    expect(formatMoney(null)).toBe('—');
  });
  it('formats windows', () => {
    expect(formatWindow({ start: null, end: null })).toBe('Zo snel mogelijk');
    expect(formatWindow({ start: '2026-09-28T10:00:00', end: '2026-09-28T12:00:00' })).toMatch(/28 sep\.? 2026 · 10:00–12:00/);
  });
  it('builds address lines and initials', () => {
    expect(addressLine({ street: 'Veldstraat', number: '20', box: '3', postcode: '9000', city: 'Gent' })).toBe('Veldstraat 20 bus 3, 9000 Gent');
    expect(initials('Kris Vermeulen')).toBe('KV');
    expect(toLocalIso(new Date(2026, 8, 28, 9, 5))).toBe('2026-09-28T09:05:00');
  });
});
