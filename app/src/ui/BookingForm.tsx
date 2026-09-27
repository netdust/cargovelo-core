import { useEffect, useMemo, useRef, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Alert, Button, Checkbox, IconButton, Input, Select, Stepper, Textarea } from '@sakaniui/react';
import type { Address, AddressBookEntry, Customer, Parcel, Quote, ServiceCode, ServiceDefinition, ShipmentInput } from '../domain/types';
import { WEIGHT_LABEL } from '../domain/status';
import { formatMoney } from '../lib/format';
import { ApiError } from '../api/client';
import { errorMessage } from '../lib/useLoad';
import { Inline, Stack } from './Page';

const EMPTY_ADDRESS: Address = { name: '', company: '', street: '', number: '', box: '', postcode: '', city: '', phone: '', email: '', instructions: '' };

export interface BookingFormProps {
  mode: 'public' | 'portal' | 'ops';
  services: ServiceDefinition[];
  addressBook?: AddressBookEntry[];
  customers?: Customer[];
  quote: (input: Partial<ShipmentInput>) => Promise<Quote>;
  onSubmit: (input: ShipmentInput) => Promise<void>;
  onCancel?: () => void;
  initial?: Partial<ShipmentInput>;
  submitLabel?: string;
}

const STEPS = [{ label: 'Dienst' }, { label: 'Ophaling' }, { label: 'Levering' }, { label: 'Bevestigen' }];

/** Four-step booking used by the website, the portal and dispatch. Live price in the side panel. */
export function BookingForm({ mode, services, addressBook = [], customers = [], quote, onSubmit, onCancel, initial, submitLabel }: BookingFormProps) {
  const [step, setStep] = useState(0);
  const [service, setService] = useState<ServiceCode | ''>(initial?.service ?? '');
  const [parcels, setParcels] = useState<Parcel[]>(initial?.parcels ?? [{ count: 1, weight_class: 's', fragile: false, cooled: false }]);
  const [pickup, setPickup] = useState<Address>({ ...EMPTY_ADDRESS, ...initial?.pickup });
  const [delivery, setDelivery] = useState<Address>({ ...EMPTY_ADDRESS, ...initial?.delivery });
  const [pickupStart, setPickupStart] = useState(initial?.pickup_window?.start?.slice(0, 16) ?? '');
  const [pickupEnd, setPickupEnd] = useState(initial?.pickup_window?.end?.slice(0, 16) ?? '');
  const [remarks, setRemarks] = useState(initial?.remarks ?? '');
  const [contact, setContact] = useState({ name: initial?.contact_name ?? '', company: initial?.contact_company ?? '', email: initial?.contact_email ?? '' });
  const [customerId, setCustomerId] = useState<string>(initial?.customer_id ? String(initial.customer_id) : '');
  const [website, setWebsite] = useState('');
  const [quoteResult, setQuoteResult] = useState<Quote | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);
  const quoteRef = useRef(quote);
  quoteRef.current = quote;

  const input = useMemo<ShipmentInput | null>(() => {
    if (!service) return null;
    return {
      service,
      parcels,
      pickup,
      delivery,
      pickup_window: { start: pickupStart ? `${pickupStart}:00` : null, end: pickupEnd ? `${pickupEnd}:00` : null },
      remarks,
      contact_email: mode === 'public' ? contact.email : undefined,
      contact_name: mode === 'public' ? contact.name : undefined,
      contact_company: mode === 'public' ? contact.company : undefined,
      customer_id: mode === 'ops' && customerId ? Number(customerId) : undefined,
    };
  }, [service, parcels, pickup, delivery, pickupStart, pickupEnd, remarks, contact, customerId, mode]);

  useEffect(() => {
    if (!service || parcels.length === 0) {
      setQuoteResult(null);
      return;
    }
    let alive = true;
    quoteRef.current({ service, parcels, customer_id: customerId ? Number(customerId) : undefined }).then(
      (q) => alive && setQuoteResult(q),
      () => alive && setQuoteResult(null),
    );
    return () => {
      alive = false;
    };
  }, [service, parcels, customerId]);

  const applyBookEntry = (setter: (a: Address) => void) => (id: string) => {
    const e = addressBook.find((a) => String(a.id) === id);
    if (e) setter({ name: e.name, company: e.company, street: e.street, number: e.number, box: e.box, postcode: e.postcode, city: e.city, phone: e.phone, email: e.email, instructions: e.instructions });
  };

  const validateStep = (): string | null => {
    if (step === 0) {
      if (!service) return 'Kies een dienst.';
      if (service === 'scheduled' && !pickupStart) return 'Kies een datum en tijdstip voor de ophaling.';
      if (mode === 'ops' && !customerId) return 'Kies een klant.';
    }
    if (step === 1 || step === 2) {
      const a = step === 1 ? pickup : delivery;
      const missing = (['name', 'street', 'number', 'postcode', 'city'] as const).filter((k) => !a[k]?.trim());
      if (missing.length) return 'Vul naam, straat, nummer, postcode en gemeente in.';
      if (!/^\d{4}$/.test(a.postcode.trim())) return 'Postcode moet 4 cijfers zijn.';
      if (step === 1 && mode === 'public' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(contact.email)) return 'Een geldig e-mailadres is verplicht voor je bevestiging.';
    }
    return null;
  };

  const next = () => {
    const err = validateStep();
    setError(err);
    if (!err) setStep((s) => Math.min(s + 1, STEPS.length - 1));
  };

  const submit = async () => {
    if (!input) return;
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    try {
      await onSubmit(mode === 'public' ? ({ ...input, website } as ShipmentInput) : input);
    } catch (e) {
      setError(errorMessage(e));
      if (e instanceof ApiError && e.field) {
        setFieldError(e.field);
        setStep(e.field === 'pickup' || e.field === 'pickup_window' || e.field === 'contact_email' ? 1 : e.field === 'delivery' ? 2 : 0);
      }
    } finally {
      setBusy(false);
    }
  };

  const addressFields = (a: Address, set: (a: Address) => void, prefix: string) => {
    const f = (k: keyof Address) => ({ value: a[k] ?? '', onChange: (e: React.ChangeEvent<HTMLInputElement>) => set({ ...a, [k]: e.target.value }) });
    return (
      <Stack gap={12}>
        {addressBook.length > 0 && (
          <Select label="Uit adresboek" placeholder="Kies een opgeslagen adres" options={addressBook.map((e) => ({ value: String(e.id), label: `${e.label} · ${e.city}` }))} onChange={applyBookEntry(set)} />
        )}
        <div className="cv-grid cv-grid--form">
          <Input label="Naam contactpersoon" required {...f('name')} autoComplete={`${prefix} name`} />
          <Input label="Bedrijf" {...f('company')} autoComplete={`${prefix} organization`} />
        </div>
        <div className="cv-grid cv-grid--form" style={{ gridTemplateColumns: '2fr 1fr 1fr' }}>
          <Input label="Straat" required {...f('street')} />
          <Input label="Nummer" required {...f('number')} />
          <Input label="Bus" {...f('box')} />
        </div>
        <div className="cv-grid cv-grid--form" style={{ gridTemplateColumns: '1fr 2fr' }}>
          <Input label="Postcode" required inputMode="numeric" maxLength={4} {...f('postcode')} />
          <Input label="Gemeente" required {...f('city')} />
        </div>
        <div className="cv-grid cv-grid--form">
          <Input label="Telefoon" type="tel" {...f('phone')} description="Voor de koerier bij problemen" />
          <Input label="E-mail" type="email" {...f('email')} description={prefix === 'shipping' ? 'Ontvanger krijgt een track & trace link' : undefined} />
        </div>
        <Textarea label="Instructies voor de koerier" rows={2} value={a.instructions ?? ''} onChange={(e) => set({ ...a, instructions: e.target.value })} placeholder="Ingang, verdieping, bel, openingsuren…" />
      </Stack>
    );
  };

  return (
    <div className="cv-booking">
      <div>
        <div style={{ marginBottom: 20 }}>
          <Stepper steps={STEPS} current={step} />
        </div>
        {error && <div style={{ marginBottom: 12 }}><Alert color="danger" title={error} /></div>}

        {step === 0 && (
          <Stack gap={16}>
            {mode === 'ops' && (
              <Select label="Klant" placeholder="Kies een klant" value={customerId} onChange={setCustomerId} options={customers.map((c) => ({ value: String(c.id), label: `${c.name}${c.type === 'account' ? ' · contract' : ''}` }))} />
            )}
            <div>
              <div className="cv-strong" style={{ marginBottom: 8 }}>Dienst</div>
              <div className="cv-service-grid" role="radiogroup">
                {services.map((s) => (
                  <button type="button" key={s.code} role="radio" aria-checked={service === s.code} className={`cv-service ${service === s.code ? 'cv-service--active' : ''}`} onClick={() => setService(s.code)}>
                    <span className="cv-service__name">{s.name}</span>
                    <span className="cv-service__desc">{s.description}</span>
                  </button>
                ))}
              </div>
            </div>
            {(service === 'scheduled' || service === 'express' || service === 'sameday') && (
              <div className="cv-grid cv-grid--form">
                <Input label={service === 'scheduled' ? 'Ophaling vanaf' : 'Ophaling vanaf (optioneel)'} type="datetime-local" value={pickupStart} onChange={(e) => setPickupStart(e.target.value)} required={service === 'scheduled'} />
                <Input label="Ophaling tot (optioneel)" type="datetime-local" value={pickupEnd} onChange={(e) => setPickupEnd(e.target.value)} />
              </div>
            )}
            <div>
              <div className="cv-strong" style={{ marginBottom: 8 }}>Pakketten</div>
              <Stack gap={8}>
                {parcels.map((p, i) => (
                  <div key={i} className="cv-parcel-row">
                    <Input label="Aantal" type="number" min={1} max={200} value={p.count} onChange={(e) => setParcels(parcels.map((x, j) => (j === i ? { ...x, count: Math.max(1, Number(e.target.value)) } : x)))} />
                    <Select label="Gewicht" value={p.weight_class} onChange={(v) => setParcels(parcels.map((x, j) => (j === i ? { ...x, weight_class: v as Parcel['weight_class'] } : x)))} options={Object.entries(WEIGHT_LABEL).map(([value, label]) => ({ value, label }))} />
                    <Checkbox label="Breekbaar" checked={p.fragile} onChange={(e) => setParcels(parcels.map((x, j) => (j === i ? { ...x, fragile: e.target.checked } : x)))} />
                    <Checkbox label="Gekoeld" checked={p.cooled} onChange={(e) => setParcels(parcels.map((x, j) => (j === i ? { ...x, cooled: e.target.checked } : x)))} />
                    <IconButton icon={Trash2} variant="ghost" size="sm" aria-label="Regel verwijderen" disabled={parcels.length === 1} onClick={() => setParcels(parcels.filter((_, j) => j !== i))} />
                  </div>
                ))}
                <div>
                  <Button type="button" variant="ghost" size="sm" leftIcon={<Plus size={14} />} onClick={() => setParcels([...parcels, { count: 1, weight_class: 's', fragile: false, cooled: false }])}>Nog een soort pakket</Button>
                </div>
              </Stack>
            </div>
          </Stack>
        )}

        {step === 1 && (
          <Stack gap={16}>
            {mode === 'public' && (
              <div className="cv-panel">
                <div className="cv-strong" style={{ marginBottom: 8 }}>Jouw gegevens (voor de bevestiging)</div>
                <div className="cv-grid cv-grid--form">
                  <Input label="Naam" required value={contact.name} onChange={(e) => setContact({ ...contact, name: e.target.value })} />
                  <Input label="Bedrijf" value={contact.company} onChange={(e) => setContact({ ...contact, company: e.target.value })} />
                  <Input label="E-mail" type="email" required value={contact.email} onChange={(e) => setContact({ ...contact, email: e.target.value })} error={fieldError === 'contact_email' ? 'Controleer dit e-mailadres' : undefined} />
                </div>
                <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" style={{ position: 'absolute', left: -9999 }} aria-hidden="true" />
              </div>
            )}
            <div className="cv-strong">Ophaaladres</div>
            {addressFields(pickup, setPickup, 'billing')}
          </Stack>
        )}

        {step === 2 && (
          <Stack gap={16}>
            <div className="cv-strong">Leveradres</div>
            {addressFields(delivery, setDelivery, 'shipping')}
          </Stack>
        )}

        {step === 3 && input && (
          <Stack gap={16}>
            <div className="cv-panel">
              <div className="cv-strong" style={{ marginBottom: 8 }}>Overzicht</div>
              <div className="cv-route">
                <div>
                  <div className="cv-route__city">{pickup.city}</div>
                  <div className="cv-route__line">{pickup.company ? `${pickup.company} · ` : ''}{pickup.name}</div>
                  <div className="cv-route__line">{pickup.street} {pickup.number}{pickup.box ? ` bus ${pickup.box}` : ''}, {pickup.postcode}</div>
                </div>
                <div className="cv-route__arrow">→</div>
                <div className="cv-route--right">
                  <div className="cv-route__city">{delivery.city}</div>
                  <div className="cv-route__line">{delivery.company ? `${delivery.company} · ` : ''}{delivery.name}</div>
                  <div className="cv-route__line">{delivery.street} {delivery.number}{delivery.box ? ` bus ${delivery.box}` : ''}, {delivery.postcode}</div>
                </div>
              </div>
            </div>
            <Textarea label="Opmerkingen voor dispatch" rows={3} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            {mode === 'public' && <p className="cv-muted">Je krijgt meteen een bevestiging met referentie en track & trace link. Dispatch bevestigt je aanvraag zo snel mogelijk.</p>}
          </Stack>
        )}

        <div className="cv-form-actions">
          <div>
            {step > 0 ? <Button type="button" variant="secondary" onClick={() => setStep((s) => s - 1)}>Vorige</Button> : onCancel ? <Button type="button" variant="ghost" onClick={onCancel}>Annuleren</Button> : null}
          </div>
          <Inline>
            {step < STEPS.length - 1 ? (
              <Button type="button" onClick={next}>Volgende</Button>
            ) : (
              <Button type="button" onClick={submit} loading={busy}>{submitLabel ?? 'Zending boeken'}</Button>
            )}
          </Inline>
        </div>
      </div>

      <aside className="cv-booking__summary">
        <div className="cv-panel">
          <div className="cv-strong" style={{ marginBottom: 8 }}>Prijs</div>
          {quoteResult ? (
            <div className="cv-quote">
              {quoteResult.breakdown.map((b, i) => (
                <div key={i} className="cv-quote__row"><span className="cv-muted">{b.label}</span><span>{formatMoney(b.cents)}</span></div>
              ))}
              <div className="cv-quote__total"><span>Totaal excl. btw</span><span>{formatMoney(quoteResult.price_cents)}</span></div>
            </div>
          ) : (
            <p className="cv-muted">Kies een dienst om de prijs te zien.</p>
          )}
          <p className="cv-subtle" style={{ marginTop: 12 }}>Prijs wordt vastgelegd bij bevestiging. Wachttijd en extra stops worden apart aangerekend.</p>
        </div>
      </aside>
    </div>
  );
}
