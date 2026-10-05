import { apiClient } from '@/lib/api/client';
import { getAccessToken } from '@/lib/auth/storage';

export interface MoneyValue {
  amount: string;
  currency: string;
}

export interface ResolvedPrice extends MoneyValue {
  source: string;
  base_amount: string;
}

export type ProductKind = 'finished_good' | 'raw_material';

export interface ProductSummary {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  visibility: string;
  backorder_policy: string;
  /** Finished goods are made and sold; raw materials (clay, glaze) are bought and consumed by production. */
  kind: ProductKind;
  /** Unit stock is counted in: pc, kg, l... */
  unit: string;
  is_active: boolean;
  category_id: string | null;
  category_name: string | null;
  from_price: MoneyValue | null;
  primary_image_url: string | null;
  variant_count: number;
  /** Sum of available-to-sell across active variants at the default location. */
  available_quantity: string;
  stock_status: StockStatus;
}

export type StockStatus = 'in_stock' | 'low_stock' | 'out_of_stock';

export interface ProductVariant {
  id: string;
  sku: string;
  name: string;
  attributes: Record<string, string>;
  is_active: boolean;
  price: ResolvedPrice;
  base_price: MoneyValue;
  unit: string;
  /** Stock level at or below which the item is flagged for re-ordering / re-making. */
  reorder_level: string | null;
  available_quantity: string;
  stock_status: StockStatus;
  /** Admin only: physical stock including reserved units. */
  on_hand?: string;
}

export interface VariantInput {
  sku: string;
  name: string;
  base_price_amount: string;
  base_price_currency: string;
  attributes: Record<string, string>;
  is_active?: boolean;
  reorder_level?: string | null;
}

export interface RecipeLine {
  id: string;
  component_variant_id: string;
  sku: string;
  product_name: string;
  variant_name: string;
  unit: string;
  quantity_per_unit: string;
  /** Current average cost of one unit of the raw material. */
  unit_cost: string;
  line_cost: string;
}

/** Raw materials one unit of a finished variant consumes. */
export interface Recipe {
  variant_id: string;
  sku: string;
  components: RecipeLine[];
  estimated_material_cost: string;
}

export interface ProductDetail extends ProductSummary {
  media: Array<{
    id: string;
    url: string;
    alt_text: string | null;
    sort_order: number;
    is_primary: boolean;
  }>;
  variants: ProductVariant[];
  availability_message: string;
}

export interface Paginated<T> {
  items: T[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface CategoryNode {
  id: string;
  name: string;
  slug: string;
  parent_id: string | null;
  sort_order: number;
  children: CategoryNode[];
}

function token(): string | undefined {
  return getAccessToken() ?? undefined;
}

export const catalogApi = {
  listProducts(params: {
    page?: number;
    per_page?: number;
    category?: string;
    visibility?: string;
    search?: string;
    status?: 'active' | 'inactive';
    kind?: ProductKind;
  } = {}): Promise<Paginated<ProductSummary>> {
    const query = new URLSearchParams();
    if (params.page) query.set('page', String(params.page));
    if (params.per_page) query.set('per_page', String(params.per_page));
    if (params.category) query.set('category', params.category);
    if (params.visibility) query.set('visibility', params.visibility);
    if (params.search) query.set('search', params.search);
    if (params.status) query.set('status', params.status);
    if (params.kind) query.set('kind', params.kind);

    const qs = query.toString();

    return apiClient.get<Paginated<ProductSummary>>(`/api/products${qs ? `?${qs}` : ''}`, token());
  },

  getProduct(id: string): Promise<ProductDetail> {
    return apiClient.get<ProductDetail>(`/api/products/${id}`, token());
  },

  createProduct(body: Record<string, unknown>): Promise<ProductDetail> {
    return apiClient.post<ProductDetail>('/api/products', body, token());
  },

  updateProduct(id: string, body: Record<string, unknown>): Promise<ProductDetail> {
    return apiClient.patch<ProductDetail>(`/api/products/${id}`, body, token());
  },

  createVariant(productId: string, body: VariantInput): Promise<ProductVariant> {
    return apiClient.post<ProductVariant>(`/api/products/${productId}/variants`, body, token());
  },

  updateVariant(productId: string, variantId: string, body: VariantInput): Promise<ProductVariant> {
    return apiClient.patch<ProductVariant>(`/api/products/${productId}/variants/${variantId}`, body, token());
  },

  getRecipe(variantId: string): Promise<Recipe> {
    return apiClient.get<Recipe>(`/api/variants/${variantId}/recipe`, token());
  },

  replaceRecipe(
    variantId: string,
    components: Array<{ component_variant_id: string; quantity_per_unit: string }>,
  ): Promise<Recipe> {
    return apiClient.put<Recipe>(`/api/variants/${variantId}/recipe`, { components }, token());
  },

  uploadProductMedia(productId: string, file: File, altText?: string): Promise<ProductDetail> {
    const body = new FormData();
    body.append('file', file);
    if (altText) body.append('alt_text', altText);

    return apiClient.post<ProductDetail>(`/api/products/${productId}/media`, body, token());
  },

  deleteProductMedia(productId: string, mediaId: string): Promise<ProductDetail> {
    return apiClient.delete<ProductDetail>(`/api/products/${productId}/media/${mediaId}`, token());
  },

  setPrimaryProductMedia(productId: string, mediaId: string): Promise<ProductDetail> {
    return apiClient.post<ProductDetail>(`/api/products/${productId}/media/${mediaId}/primary`, undefined, token());
  },

  listCategories(): Promise<{ items: CategoryNode[] }> {
    return apiClient.get<{ items: CategoryNode[] }>('/api/categories', token());
  },

  createCategory(body: Record<string, unknown>): Promise<CategoryNode> {
    return apiClient.post<CategoryNode>('/api/categories', body, token());
  },
};

export function formatMoney(value: MoneyValue | null | undefined): string {
  if (!value) return '—';
  const amount = Number.parseFloat(value.amount);
  return new Intl.NumberFormat('fr-MA', {
    style: 'currency',
    currency: value.currency,
    minimumFractionDigits: 2,
  }).format(amount);
}

/** Category tree → flat list with "Parent / Child" labels for selects. */
export function flattenCategories(
  nodes: Array<Pick<CategoryNode, 'id' | 'name'> & { children: CategoryNode[] }>,
  prefix = '',
): Array<{ id: string; label: string }> {
  return nodes.flatMap((node) => [
    { id: node.id, label: `${prefix}${node.name}` },
    ...flattenCategories(node.children, `${prefix}${node.name} / `),
  ]);
}
