import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import { Toast } from '@sakaniui/react';

type Status = 'success' | 'error' | 'info';
interface Item {
  id: number;
  status: Status;
  title: string;
  description?: string;
}

interface ToastApi {
  toast: (status: Status, title: string, description?: string) => void;
  success: (title: string, description?: string) => void;
  error: (title: string, description?: string) => void;
}

const ToastCtx = createContext<ToastApi | null>(null);

/** Sakani ships the Toast surface only; this adds the queue, stacking and auto-dismiss. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Item[]>([]);
  const nextId = useRef(1);

  const remove = useCallback((id: number) => setItems((list) => list.filter((t) => t.id !== id)), []);
  const toast = useCallback(
    (status: Status, title: string, description?: string) => {
      const id = nextId.current++;
      setItems((list) => [...list.slice(-3), { id, status, title, description }]);
      window.setTimeout(() => remove(id), status === 'error' ? 8000 : 4000);
    },
    [remove],
  );
  const value = useMemo<ToastApi>(
    () => ({ toast, success: (t, d) => toast('success', t, d), error: (t, d) => toast('error', t, d) }),
    [toast],
  );

  return (
    <ToastCtx.Provider value={value}>
      {children}
      <div className="cv-toasts" aria-live="polite">
        {items.map((t) => (
          <Toast key={t.id} status={t.status} title={t.title} description={t.description} onDismiss={() => remove(t.id)} />
        ))}
      </div>
    </ToastCtx.Provider>
  );
}

export function useToast(): ToastApi {
  const c = useContext(ToastCtx);
  if (!c) throw new Error('ToastProvider missing');
  return c;
}
