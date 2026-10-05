import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { SlidersHorizontalIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { PaginationBar } from '@/components/PaginationBar'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Separator } from '@/components/ui/separator'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  StockAdjustmentDialog,
  type AdjustmentTarget,
} from '@/features/admin/components/StockAdjustmentDialog'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { inventoryApi, type StockMovement, type StockRow } from '@/lib/api/inventory'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatWithUnit } from '@/lib/format'

type KindFilter = 'all' | NonNullable<Parameters<typeof inventoryApi.listStock>[2]>

const CURRENCY = 'TND'

const stockColumns: ResponsiveTableColumn<StockRow>[] = [
  {
    key: 'product',
    header: 'Product',
    primary: true,
    cell: (row) => (
      <span className="flex flex-wrap items-center gap-2">
        {`${row.product_name} — ${row.variant_name}`}
        {row.is_low_stock ? <Badge variant="warning">Low stock</Badge> : null}
      </span>
    ),
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
    cell: (row) => formatWithUnit(row.physical_on_hand, row.unit, 4),
  },
  {
    key: 'reserved',
    header: 'Reserved',
    className: 'tabular-nums',
    cell: (row) => formatWithUnit(row.reserved, row.unit, 4),
  },
  {
    key: 'available',
    header: 'Available',
    mobile: true,
    className: 'tabular-nums',
    cell: (row) => formatWithUnit(row.available_to_sell, row.unit, 4),
  },
  {
    key: 'reorder_level',
    header: 'Reorder at',
    className: 'tabular-nums',
    cell: (row) => (row.reorder_level === null ? '—' : formatWithUnit(row.reorder_level, row.unit, 4)),
  },
  {
    key: 'average_cost',
    header: 'Avg cost',
    className: 'tabular-nums',
    cell: (row) =>
      Number(row.average_cost) > 0 ? <MoneyText amount={row.average_cost} currency={CURRENCY} /> : <span className="text-muted-foreground">Unknown</span>,
  },
  {
    key: 'stock_value',
    header: 'Value',
    className: 'tabular-nums',
    cell: (row) => <MoneyText amount={row.stock_value} currency={CURRENCY} />,
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
    key: 'unit_cost',
    header: 'Unit cost',
    className: 'tabular-nums',
    cell: (movement) =>
      movement.unit_cost && Number(movement.unit_cost) > 0 ? <MoneyText amount={movement.unit_cost} currency={CURRENCY} /> : '—',
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
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [adjustTarget, setAdjustTarget] = useState<AdjustmentTarget | null>(null)
  const [kind, setKind] = useState<KindFilter>('all')
  const [stockPage, setStockPage] = useState(1)
  const [movementPage, setMovementPage] = useState(1)
  const { can } = useAuth()
  const canAdjust = can(PERMISSIONS.inventoryAdjust)

  const openAdjust = (target: AdjustmentTarget | null = null) => {
    setAdjustTarget(target)
    setAdjustOpen(true)
  }

  const { data: stock, isLoading: stockLoading, error: stockError } = useQuery({
    queryKey: ['admin', 'inventory', 'stock', kind, stockPage],
    queryFn: () => inventoryApi.listStock(stockPage, undefined, kind === 'all' ? undefined : kind),
    placeholderData: (previous) => previous,
  })

  const { data: movements, isLoading: movementsLoading, error: movementsError } = useQuery({
    queryKey: ['admin', 'inventory', 'movements', selectedVariant, movementPage],
    queryFn: () => inventoryApi.listMovements(movementPage, selectedVariant),
    placeholderData: (previous) => previous,
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
        action={
          canAdjust ? (
            <Button onClick={() => openAdjust()}>
              <SlidersHorizontalIcon data-icon="inline-start" />
              Adjust stock
            </Button>
          ) : null
        }
      />

      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="font-heading text-lg font-medium">Stock balances</h2>
            <ToggleGroup
              variant="outline"
              size="sm"
              aria-label="Kind of item"
              value={[kind]}
              onValueChange={(next) => {
                if (next[0]) {
                  setKind(next[0] as KindFilter)
                  setStockPage(1)
                }
              }}
            >
              <ToggleGroupItem value="all">All</ToggleGroupItem>
              <ToggleGroupItem value="finished_good">Finished goods</ToggleGroupItem>
              <ToggleGroupItem value="raw_material">Raw materials</ToggleGroupItem>
            </ToggleGroup>
          </div>
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
                <div className="flex gap-1">
                  {canAdjust ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() =>
                        openAdjust({
                          variant_id: row.variant_id,
                          label: `${row.product_name} — ${row.variant_name}`,
                          sku: row.sku,
                          location_id: row.location_id,
                        })
                      }
                    >
                      Adjust
                    </Button>
                  ) : null}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setSelectedVariant(row.variant_id)
                      setMovementPage(1)
                    }}
                  >
                    Ledger
                  </Button>
                </div>
              )}
            />
          ) : null}
        </QueryState>
        {stock ? (
          <PaginationBar
            page={stock.meta.page}
            totalPages={stock.meta.total_pages}
            total={stock.meta.total}
            noun="stock lines"
            onPageChange={setStockPage}
          />
        ) : null}
      </div>

      <Separator />

      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between gap-3">
          <h2 className="font-heading text-lg font-medium">
            Movement ledger{selectedVariant ? ' (filtered)' : ''}
          </h2>
          {selectedVariant ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => {
                setSelectedVariant(undefined)
                setMovementPage(1)
              }}
            >
              Show all
            </Button>
          ) : null}
        </div>

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
        {movements ? (
          <PaginationBar
            page={movements.meta.page}
            totalPages={movements.meta.total_pages}
            total={movements.meta.total}
            noun="movements"
            onPageChange={setMovementPage}
          />
        ) : null}
      </div>

      <StockAdjustmentDialog open={adjustOpen} onOpenChange={setAdjustOpen} target={adjustTarget} />
    </section>
  )
}
