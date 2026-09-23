import { apiClient } from '@/lib/api/client';
import type { MoneyValue, Paginated } from '@/lib/api/catalog';
import { getAccessToken } from '@/lib/auth/storage';

export interface CustomerSummary {
  id: string;
  type: string;
  display_name: string;
  legal_name: string | null;
  is_active: boolean;
  portal_user_id: string | null;
}

export interface CustomerDetail extends CustomerSummary {
  tax_id: string | null;
  vat_number: string | null;
  notes: string | null;
  identities: Array<{ id: string; type: string; value: string }>;
  addresses: Array<{
    id: string;
    type: string;
    line1: string;
    line2: string | null;
    city: string;
    postal_code: string;
    country: string;
    is_default: boolean;
  }>;
  contacts: Array<{
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    role: string | null;
    is_primary: boolean;
  }>;
  price_overrides?: Array<{
    id: string;
    variant_id: string;
    variant_sku: string;
    price: MoneyValue;
  }>;
  orders_count?: number;
  balance?: MoneyValue;
}

function token(): string | undefined {
  return getAccessToken() ?? undefined;
}

export const customersApi = {
  list(params: { page?: number; per_page?: number; search?: string } = {}): Promise<Paginated<CustomerSummary>> {
    const query = new URLSearchParams();
    if (params.page) query.set('page', String(params.page));
    if (params.per_page) query.set('per_page', String(params.per_page));
    if (params.search) query.set('search', params.search);
    const qs = query.toString();

    return apiClient.get<Paginated<CustomerSummary>>(`/api/customers${qs ? `?${qs}` : ''}`, token());
  },

  get(id: string): Promise<CustomerDetail> {
    return apiClient.get<CustomerDetail>(`/api/customers/${id}`, token());
  },

  create(body: Record<string, unknown>): Promise<CustomerDetail> {
    return apiClient.post<CustomerDetail>('/api/customers', body, token());
  },

  update(id: string, body: Record<string, unknown>): Promise<CustomerDetail> {
    return apiClient.patch<CustomerDetail>(`/api/customers/${id}`, body, token());
  },

  getProfile(): Promise<CustomerDetail> {
    return apiClient.get<CustomerDetail>('/api/customers/me', token());
  },

  updateProfile(body: Record<string, unknown>): Promise<CustomerDetail> {
    return apiClient.patch<CustomerDetail>('/api/customers/me', body, token());
  },

  createPriceOverride(customerId: string, body: Record<string, unknown>): Promise<Record<string, unknown>> {
    return apiClient.post(`/api/customers/${customerId}/price-overrides`, body, token());
  },

  getBalance(id: string): Promise<MoneyValue> {
    return apiClient.get<MoneyValue>(`/api/customers/${id}/balance`, token());
  },
};
