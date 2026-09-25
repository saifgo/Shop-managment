import { describe, expect, it } from 'vitest'
import { documentTypeLabel, filenameFromDisposition, isDocumentType, previewLineTotals } from '@/lib/api/documents'

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

describe('filenameFromDisposition', () => {
  it('reads unquoted and quoted filenames, as Symfony sends either', () => {
    expect(filenameFromDisposition('attachment; filename=Facture_INV-2026-000001.pdf')).toBe('Facture_INV-2026-000001.pdf')
    expect(filenameFromDisposition('inline; filename="Bon_de_livraison_DN 1.pdf"')).toBe('Bon_de_livraison_DN 1.pdf')
  })

  it('returns null without a filename', () => {
    expect(filenameFromDisposition(null)).toBeNull()
    expect(filenameFromDisposition('attachment')).toBeNull()
  })
})
