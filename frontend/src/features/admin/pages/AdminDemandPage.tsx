import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { demandApi } from '@/lib/api/orders'

type DemandView = 'customer' | 'product'

export function AdminDemandPage() {
  const [view, setView] = useState<DemandView>('customer')

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'demand', view],
    queryFn: () => (view === 'customer' ? demandApi.byCustomer() : demandApi.byProduct()),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Demand"
        action={
          <ToggleGroup
            variant="outline"
            spacing={0}
            value={[view]}
            onValueChange={(next) => {
              if (next[0]) setView(next[0] as DemandView)
            }}
            aria-label="Demand view"
          >
            <ToggleGroupItem value="customer">By customer</ToggleGroupItem>
            <ToggleGroupItem value="product">By product</ToggleGroupItem>
          </ToggleGroup>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load demand projections.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No open demand"
        emptyDescription="Confirmed orders with outstanding quantities will appear here."
      >
        {data ? (
          <ResponsiveTable
            wide
            data={data.items}
            getRowKey={(row) =>
              `${row.sku}-${row.customer_name ?? 'product'}-${row.ordered}-${row.reserved}-${row.backordered}`
            }
            columns={[
              { key: 'product', header: 'Product', primary: true, cell: (row) => row.product_name },
              {
                key: 'variant',
                header: 'Variant',
                cell: (row) => `${row.variant_name} (${row.sku})`,
              },
              ...(view === 'customer'
                ? [
                    {
                      key: 'customer',
                      header: 'Customer',
                      cell: (row: (typeof data.items)[number]) => row.customer_name ?? '—',
                    },
                  ]
                : []),
              {
                key: 'ordered',
                header: 'Ordered',
                cell: (row: (typeof data.items)[number]) => <span className="tabular-nums">{row.ordered}</span>,
              },
              {
                key: 'reserved',
                header: 'Reserved',
                cell: (row: (typeof data.items)[number]) => <span className="tabular-nums">{row.reserved}</span>,
              },
              {
                key: 'backordered',
                header: 'Backordered',
                cell: (row: (typeof data.items)[number]) => <span className="tabular-nums">{row.backordered}</span>,
              },
              ...(view === 'product'
                ? [
                    {
                      key: 'to_produce',
                      header: 'To produce',
                      cell: (row: (typeof data.items)[number]) => (
                        <span className="tabular-nums">{row.to_produce ?? row.net_demand ?? '0.0000'}</span>
                      ),
                    },
                  ]
                : []),
            ]}
          />
        ) : null}
      </QueryState>
    </section>
  )
}
