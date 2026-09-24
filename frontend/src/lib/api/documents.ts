import { apiClient } from '@/lib/api/client'
import type { MoneyAmount, Paginated } from '@/lib/api/orders'
import { getAccessToken } from '@/lib/auth/storage'

export type DocumentType =
  | 'INVOICE'
  | 'QUOTE'
  | 'PROFORMA'
  | 'SALES_ORDER'
  | 'DELIVERY_NOTE'
  | 'GOODS_ISSUE'
  | 'CREDIT_NOTE'

/** Types an admin can create by hand; credit notes come from the invoice credit flow. */
export const MANUAL_DOCUMENT_TYPES: DocumentType[] = [
  'INVOICE',
  'QUOTE',
  'PROFORMA',
  'SALES_ORDER',
  'DELIVERY_NOTE',
  'GOODS_ISSUE',
]

export const DOCUMENT_TYPE_LABELS: Record<DocumentType, { label: string; french: string }> = {
  INVOICE: { label: 'Invoice', french: 'Facture' },
  QUOTE: { label: 'Quote', french: 'Devis' },
  PROFORMA: { label: 'Proforma invoice', french: 'Facture proforma' },
  SALES_ORDER: { label: 'Order form', french: 'Bon de commande' },
  DELIVERY_NOTE: { label: 'Delivery note', french: 'Bon de livraison' },
  GOODS_ISSUE: { label: 'Goods issue note', french: 'Bon de sortie' },
  CREDIT_NOTE: { label: 'Credit note', french: 'Avoir' },
}

export function documentTypeLabel(type: string): string {
  const labels = DOCUMENT_TYPE_LABELS[type as DocumentType]
  return labels ? `${labels.french} (${labels.label})` : type
}

export function isDocumentType(value: string | null): value is DocumentType {
  return value !== null && value in DOCUMENT_TYPE_LABELS
}

export interface DocumentLine {
  id: string
  description: string
  sku: string
  quantity: string
  unit_price: MoneyAmount
  tax_rate: string
  discount_amount: MoneyAmount
  line_subtotal: MoneyAmount
  line_tax: MoneyAmount
  line_total: MoneyAmount
}

export interface CommercialDocument {
  id: string
  document_type: DocumentType
  status: string
  document_number: string | null
  customer_id: string
  customer_display_name: string
  order_id: string | null
  currency: string
  subtotal: MoneyAmount
  tax_total: MoneyAmount
  discount_total: MoneyAmount
  grand_total: MoneyAmount
  amount_paid: MoneyAmount
  amount_due: MoneyAmount
  is_posted: boolean
  issued_at: string | null
  due_date: string | null
  notes: string | null
  created_at: string
  lines: DocumentLine[]
}

export interface ManualDocumentLineInput {
  variant_id?: string
  description?: string
  sku?: string
  quantity: string
  unit_price?: string
  tax_rate?: string
  discount_amount?: string
}

export interface CreateManualDocumentInput {
  document_type: DocumentType
  customer_id: string
  currency?: string
  notes?: string
  due_date?: string
  issue: boolean
  lines: ManualDocumentLineInput[]
}

function token(): string | undefined {
  return getAccessToken() ?? undefined
}

export const documentsApi = {
  list(params: { type?: DocumentType; page?: number; customer_id?: string } = {}) {
    const query = new URLSearchParams()
    if (params.type) query.set('type', params.type)
    if (params.page) query.set('page', String(params.page))
    if (params.customer_id) query.set('customer_id', params.customer_id)
    const qs = query.toString()

    return apiClient.get<Paginated<CommercialDocument>>(`/api/documents${qs ? `?${qs}` : ''}`, token())
  },

  get(id: string) {
    return apiClient.get<CommercialDocument>(`/api/documents/${id}`, token())
  },

  create(input: CreateManualDocumentInput, idempotencyKey: string) {
    return apiClient.post<CommercialDocument>('/api/documents', input, token(), {
      'Idempotency-Key': idempotencyKey,
    })
  },

  issue(id: string, dueDate?: string) {
    return apiClient.post<CommercialDocument>(`/api/documents/${id}/issue`, { due_date: dueDate }, token())
  },

  cancel(id: string) {
    return apiClient.post<CommercialDocument>(`/api/documents/${id}/cancel`, {}, token())
  },

  /** Fetches the PDF with the bearer token (a plain link cannot send it) and saves it. */
  async downloadPdf(document: { id: string; document_number?: string | null }) {
    const response = await fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/documents/${document.id}/download`, {
      headers: { Authorization: `Bearer ${token() ?? ''}` },
    })

    if (!response.ok) {
      throw new Error(
        response.status === 404 ? 'The PDF is still being generated. Try again in a moment.' : 'Download failed.',
      )
    }

    const url = URL.createObjectURL(await response.blob())
    const link = window.document.createElement('a')
    link.href = url
    link.download = `${document.document_number ?? document.id}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  },
}

/**
 * Mirrors DocumentSnapshotBuilder::lineTotals on the backend so the form can
 * preview totals before saving. Tax rate is a percentage.
 */
export function previewLineTotals(quantity: string, unitPrice: string, taxRate: string, discount: string) {
  const subtotal = toNumber(quantity) * toNumber(unitPrice)
  const taxable = subtotal - toNumber(discount)
  const tax = (taxable * toNumber(taxRate)) / 100

  return { subtotal, discount: toNumber(discount), tax, total: taxable + tax }
}

function toNumber(value: string): number {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : 0
}
