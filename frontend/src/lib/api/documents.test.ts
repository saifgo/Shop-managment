import { describe, expect, it } from 'vitest'
import { documentTypeLabel, isDocumentType, previewLineTotals } from '@/lib/api/documents'

describe('previewLineTotals', () => {
  // Same figures as ManualDocumentsTest on the backend: 2 × 100, 10 discount, 20% tax.
  it('applies the discount before tax, like the backend', () => {
    expect(previewLineTotals('2', '100', '20', '10')).toEqual({ subtotal: 200, discount: 10, tax: 38, total: 228 })
  })

  it('treats blank or invalid inputs as zero', () => {
    expect(previewLineTotals('', 'abc', '20', '')).toEqual({ subtotal: 0, discount: 0, tax: 0, total: 0 })
  })
})

describe('document type helpers', () => {
  it('labels types in French and English', () => {
    expect(documentTypeLabel('GOODS_ISSUE')).toBe('Bon de sortie (Goods issue note)')
    expect(documentTypeLabel('UNKNOWN')).toBe('UNKNOWN')
  })

  it('recognises only known types', () => {
    expect(isDocumentType('PROFORMA')).toBe(true)
    expect(isDocumentType('RECEIPT')).toBe(false)
    expect(isDocumentType(null)).toBe(false)
  })
})
