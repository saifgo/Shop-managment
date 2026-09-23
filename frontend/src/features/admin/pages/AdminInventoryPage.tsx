import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { Button } from '@/components/ui/button'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Separator } from '@/components/ui/separator'
import { inventoryApi, type StockMovement, type StockRow } from '@/lib/api/inventory'

const stockColumns: ResponsiveTableColumn<StockRow>[] = [
  {
    key: 'product',
    header: 'Product',
    primary: true,
    cell: (row) => `${row.product_name} — ${row.variant_name}`,
  },
  {
    key: 'sku',
    header: 'SKU',
    mobile: true,
    cell: (row) => row.sku,
  },
  {
    key: 'on_hand',
    header: 'On hand',
    className: 'tabular-nums',
    cell: (row) => row.physical_on_hand,
  },
  {
    key: 'reserved',
    header: 'Reserved',
    className: 'tabular-nums',
    cell: (row) => row.reserved,
  },
  {
    key: 'available',
    header: 'Available',
    mobile: true,
    className: 'tabular-nums',
    cell: (row) => row.available_to_sell,
  },
  {
    key: 'demand',
    header: 'Demand',
    className: 'tabular-nums',
    cell: (row) => row.confirmed_demand,
  },
  {
    key: 'net_production_demand',
    header: 'Net production demand',
    className: 'tabular-nums',
    cell: (row) => row.net_production_demand,
  },
]

const movementColumns: ResponsiveTableColumn<StockMovement>[] = [
  {
    key: 'when',
    header: 'When',
    primary: true,
    cell: (movement) => new Date(movement.created_at).toLocaleString(),
  },
  {
    key: 'type',
    header: 'Type',
    mobile: true,
    cell: (movement) => movement.movement_type,
  },
  {
    key: 'sku',
    header: 'SKU',
    mobile: true,
    cell: (movement) => movement.sku,
  },
  {
    key: 'qty',
    header: 'Qty Δ',
    mobile: true,
    className: 'tabular-nums',
    cell: (movement) => movement.quantity_delta,
  },
  {
    key: 'reserved',
    header: 'Reserved Δ',
    className: 'tabular-nums',
    cell: (movement) => movement.reserved_delta,
  },
  {
    key: 'source',
    header: 'Source',
    cell: (movement) => `${movement.source_type} / ${movement.source_id.slice(0, 8)}…`,
  },
  {
    key: 'notes',
    header: 'Notes',
    cell: (movement) => movement.notes ?? movement.reference ?? '—',
  },
]

export function AdminInventoryPage() {
  const [variantFilter, setVariantFilter] = useState('')
  const [selectedVariant, setSelectedVariant] = useState<string | undefined>()

  const { data: stock, isLoading: stockLoading, error: stockError } = useQuery({
    queryKey: ['admin', 'inventory', 'stock'],
    queryFn: () => inventoryApi.listStock(1),
  })

  const { data: movements, isLoading: movementsLoading, error: movementsError } = useQuery({
    queryKey: ['admin', 'inventory', 'movements', selectedVariant],
    queryFn: () => inventoryApi.listMovements(1, selectedVariant),
  })

  const filteredStock = stock?.items.filter((row) =>
    !variantFilter
    || row.sku.toLowerCase().includes(variantFilter.toLowerCase())
    || row.product_name.toLowerCase().includes(variantFilter.toLowerCase()),
  )

  return (
    <section className="flex flex-col gap-8">
      <PageHeader
        title="Inventory"
        description="Stock balances and immutable movement ledger."
      />

      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="font-heading text-lg font-medium">Stock balances</h2>
          <Field className="sm:w-64">
            <FieldLabel htmlFor="stock-filter" className="sr-only">
              Filter stock
            </FieldLabel>
            <Input
              id="stock-filter"
              placeholder="Filter by SKU or product"
              value={variantFilter}
              onChange={(e) => setVariantFilter(e.target.value)}
            />
          </Field>
        </div>

        <QueryState
          isLoading={stockLoading}
          error={stockError ? 'Failed to load stock.' : null}
          isEmpty={!!filteredStock && filteredStock.length === 0}
          emptyTitle={variantFilter ? 'No matching stock' : 'No stock balances'}
          emptyDescription={
            variantFilter
              ? 'Try a different SKU or product name.'
              : 'There is no stock to show yet.'
          }
        >
          {filteredStock ? (
            <ResponsiveTable
              wide
              data={filteredStock}
              columns={stockColumns}
              getRowKey={(row) => `${row.variant_id}-${row.location_id}`}
              rowAction={(row) => (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedVariant(row.variant_id)}
                >
                  Ledger
                </Button>
              )}
            />
          ) : null}
        </QueryState>
      </div>

      <Separator />

      <div className="flex flex-col gap-4">
        <h2 className="font-heading text-lg font-medium">
          Movement ledger{selectedVariant ? ' (filtered)' : ''}
        </h2>

        <QueryState
          isLoading={movementsLoading}
          error={movementsError ? 'Failed to load movements.' : null}
          isEmpty={!!movements && movements.items.length === 0}
          emptyTitle="No movements"
          emptyDescription="There is no ledger activity to show yet."
        >
          {movements ? (
            <ResponsiveTable
              wide
              data={movements.items}
              columns={movementColumns}
              getRowKey={(movement) => movement.id}
            />
          ) : null}
        </QueryState>
      </div>
    </section>
  )
}
