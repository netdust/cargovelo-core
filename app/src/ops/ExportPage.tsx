import { useState } from 'react';
import { Download } from 'lucide-react';
import { Button, Input, Select } from '@sakaniui/react';
import { useApi } from '../lib/api-context';
import { useLoad } from '../lib/useLoad';
import { todayIso } from '../lib/format';
import { PageHeader, Stack } from '../ui/Page';

export function ExportPage() {
  const api = useApi();
  const customers = useLoad(() => api.customers(), []);
  const first = todayIso().slice(0, 8) + '01';
  const [from, setFrom] = useState(first);
  const [to, setTo] = useState(todayIso());
  const [customer, setCustomer] = useState('');

  return (
    <div style={{ maxWidth: 560 }}>
      <PageHeader title="Export voor facturatie" description="Geleverde zendingen met de vastgelegde prijs, als CSV voor het boekhoudpakket. Stride-regel: dit systeem factureert niet." />
      <div className="cv-panel">
        <Stack gap={12}>
          <div className="cv-grid cv-grid--form">
            <Input label="Van" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            <Input label="Tot en met" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <Select label="Klant" placeholder="Alle klanten" value={customer} onChange={setCustomer} options={[{ value: '', label: 'Alle klanten' }, ...(customers.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))]} />
          <div>
            <Button leftIcon={<Download size={16} />} onClick={() => { const a = document.createElement('a'); a.href = api.opsExportUrl(from, to, customer ? Number(customer) : undefined); a.download = `cargovelo-geleverd-${from}-${to}.csv`; a.click(); }}>Download CSV</Button>
          </div>
          <p className="cv-subtle">Kolommen: referentie, klant, geleverd op, dienst, hub, ophaling, levering, pakketten, prijs, prijs aangepast, koerier, kanaal. Scheidingsteken ; en UTF-8 met BOM zodat Excel het meteen opent.</p>
        </Stack>
      </div>
    </div>
  );
}
