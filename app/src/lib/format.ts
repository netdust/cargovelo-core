const money = new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' });

export function formatMoney(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return '—';
  return money.format(cents / 100);
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('nl-BE', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleTimeString('nl-BE', { hour: '2-digit', minute: '2-digit' });
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return `${formatDate(iso)} · ${formatTime(iso)}`;
}

export function formatWindow(w: { start: string | null; end: string | null } | undefined): string {
  if (!w || (!w.start && !w.end)) return 'Zo snel mogelijk';
  if (w.start && w.end) {
    const sameDay = w.start.slice(0, 10) === w.end.slice(0, 10);
    return sameDay
      ? `${formatDate(w.start)} · ${formatTime(w.start)}–${formatTime(w.end)}`
      : `${formatDateTime(w.start)} → ${formatDateTime(w.end)}`;
  }
  return formatDateTime(w.start ?? w.end);
}

export function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const min = Math.round(diff / 60000);
  if (min < 1) return 'zonet';
  if (min < 60) return `${min} min geleden`;
  const h = Math.round(min / 60);
  if (h < 24) return `${h} u geleden`;
  const d = Math.round(h / 24);
  return `${d} d geleden`;
}

export function todayIso(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function toLocalIso(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

export function addressLine(a: { street: string; number: string; box?: string; postcode: string; city: string }): string {
  const box = a.box ? ` bus ${a.box}` : '';
  return `${a.street} ${a.number}${box}, ${a.postcode} ${a.city}`;
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('');
}
