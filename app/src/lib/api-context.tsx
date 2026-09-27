import { createContext, useContext, type ReactNode } from 'react';
import type { Api } from '../api/client';
import type { AppContext } from '../domain/types';

interface Ctx {
  api: Api;
  context: AppContext;
}

const ApiCtx = createContext<Ctx | null>(null);

export function ApiProvider({ api, context, children }: Ctx & { children: ReactNode }) {
  return <ApiCtx.Provider value={{ api, context }}>{children}</ApiCtx.Provider>;
}

export function useApi(): Api {
  const c = useContext(ApiCtx);
  if (!c) throw new Error('ApiProvider missing');
  return c.api;
}

export function useAppContext(): AppContext {
  const c = useContext(ApiCtx);
  if (!c) throw new Error('ApiProvider missing');
  return c.context;
}
