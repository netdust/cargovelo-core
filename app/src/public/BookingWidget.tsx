import { useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { Button } from '@sakaniui/react';
import { useApi, useAppContext } from '../lib/api-context';
import { BookingForm } from '../ui/BookingForm';
import type { PublicBookingResult } from '../api/client';
import { formatMoney } from '../lib/format';
import { KeyValue, Stack } from '../ui/Page';

/** [cargovelo_booking]: the website form. Same form as the portal, guest mode with contact block + honeypot. */
export function BookingWidget() {
  const api = useApi();
  const ctx = useAppContext();
  const [done, setDone] = useState<PublicBookingResult | null>(null);

  if (done) {
    return (
      <div className="cv-root" style={{ maxWidth: 560, margin: '0 auto', padding: 16 }}>
        <div className="cv-panel">
          <Stack gap={12}>
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', color: 'var(--color-success-fg)' }}><CheckCircle2 /> <span className="cv-strong" style={{ fontSize: 18 }}>{done.status === 'confirmed' ? 'Zending bevestigd' : 'Aanvraag ontvangen'}</span></div>
            <p>Je krijgt zo een bevestiging per e-mail met deze referentie en de track & trace link. {done.status === 'requested' && 'Onze dispatch bevestigt je aanvraag zo snel mogelijk.'}</p>
            <KeyValue rows={[['Referentie', <span className="cv-mono cv-strong">{done.reference}</span>], ['Prijs excl. btw', formatMoney(done.price_cents)]]} />
            <div>
              <a href={done.tracking_url}><Button variant="secondary">Volg je zending</Button></a>
              <Button variant="ghost" onClick={() => setDone(null)}>Nog een zending</Button>
            </div>
          </Stack>
        </div>
      </div>
    );
  }

  return (
    <div className="cv-root" style={{ padding: 16 }}>
      <BookingForm mode="public" services={ctx.services} quote={(i) => api.publicQuote(i)} onSubmit={async (input) => setDone(await api.publicBooking(input))} submitLabel="Zending aanvragen" />
    </div>
  );
}
