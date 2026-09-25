import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { PortalCatalogPage } from '@/features/portal/pages/PortalCatalogPage'

vi.mock('@/lib/api/catalog', () => ({
  catalogApi: {
    listCategories: vi.fn().mockResolvedValue({ items: [] }),
    listProducts: vi.fn().mockResolvedValue({
      items: [
        {
          id: '01JPROD001',
          name: 'Berber Tagine',
          slug: 'berber-tagine',
          description: 'Handcrafted tagine',
          visibility: 'public',
          backorder_policy: 'allow',
          is_active: true,
          category_id: null,
          category_name: 'Tableware',
          from_price: { amount: '249.0000', currency: 'TND' },
          primary_image_url: null,
          variant_count: 3,
          available_quantity: '0.0000',
          stock_status: 'out_of_stock',
        },
      ],
      meta: { page: 1, per_page: 24, total: 1, total_pages: 1 },
    }),
  },
  flattenCategories: () => [],
  formatMoney: (value: { amount: string; currency: string } | null) =>
    value ? `${value.amount} ${value.currency}` : '—',
}))

describe('PortalCatalogPage', () => {
  it('renders products from the API', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <PortalCatalogPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Berber Tagine')).toBeInTheDocument()
    expect(screen.getByText(/249/)).toBeInTheDocument()
    // Out of stock but backorderable pieces are offered as made to order.
    expect(screen.getByText('Made to order')).toBeInTheDocument()
  })
})
