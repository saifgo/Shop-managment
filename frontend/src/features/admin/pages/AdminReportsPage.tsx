import { useQuery } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Badge } from '@/components/ui/badge'
import { reportsApi } from '@/lib/api/reports'

function ReportMoney({ value }: { value: unknown }) {
  if (!value || typeof value !== 'object') return '—'
  const money = value as { amount?: string; currency?: string }
  if (!money.amount || !money.currency) return '—'
  return <MoneyText amount={money.amount} currency={money.currency} />
}

function AgingTable({
  title,
  buckets,
}: {
  title: string
  buckets: Record<string, { amount: string; currency: string }>
}) {
  return (
    <div className="flex flex-col gap-3">
      <h2 className="font-heading text-lg font-medium">{title}</h2>
      <ResponsiveTable
        data={Object.entries(buckets).map(([bucket, amount]) => ({ bucket, amount }))}
        getRowKey={(row) => row.bucket}
        columns={[
          {
            key: 'bucket',
            header: 'Bucket',
            primary: true,
            cell: (row) => row.bucket.replaceAll('_', ' '),
          },
          {
            key: 'amount',
            header: 'Amount',
            cell: (row) => <MoneyText amount={row.amount.amount} currency={row.amount.currency} />,
          },
        ]}
      />
    </div>
  )
}

export function AdminReportsPage() {
  const sales = useQuery({ queryKey: ['reports', 'sales'], queryFn: () => reportsApi.sales() })
  const margin = useQuery({ queryKey: ['reports', 'margin'], queryFn: () => reportsApi.margin() })
  const stock = useQuery({ queryKey: ['reports', 'stock'], queryFn: () => reportsApi.stock() })
  const yieldReport = useQuery({ queryKey: ['reports', 'yield'], queryFn: () => reportsApi.productionYield() })
  const receivables = useQuery({
    queryKey: ['reports', 'receivables-aging'],
    queryFn: () => reportsApi.receivablesAging(),
  })
  const payables = useQuery({ queryKey: ['reports', 'payables-aging'], queryFn: () => reportsApi.payablesAging() })

  const stockItems =
    (stock.data as { items?: Array<{ sku: string; product_name: string; available_to_sell: string; is_low_stock: boolean }> } | undefined)
      ?.items ?? []
  const yieldData = yieldReport.data as { yield_pct?: string | null; loss_total?: string; output_total?: string } | undefined
  const receivablesBuckets = (receivables.data as { receivables_aging?: Record<string, { amount: string; currency: string }> } | undefined)
    ?.receivables_aging
  const payablesBuckets = (payables.data as { payables_aging?: Record<string, { amount: string; currency: string }> } | undefined)
    ?.payables_aging

  const salesCount = (sales.data as { order_count?: number } | undefined)?.order_count
  const salesRevenue = (sales.data as { revenue?: { amount: string; currency: string } } | undefined)?.revenue
  const marginRevenue = (margin.data as { revenue?: { amount: string; currency: string } } | undefined)?.revenue
  const marginNote = (margin.data as { note?: string } | undefined)?.note ?? ''
  const lowStockCount = (stock.data as { low_stock_count?: number } | undefined)?.low_stock_count

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Operational reports" />

      <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
        <div className="flex flex-col gap-1">
          <p className="text-sm text-muted-foreground">Sales orders</p>
          <p className="text-2xl tabular-nums">{salesCount ?? '—'}</p>
          <p className="text-sm text-muted-foreground">
            <ReportMoney value={salesRevenue} />
          </p>
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-sm text-muted-foreground">Invoiced revenue</p>
          <p className="text-2xl tabular-nums">
            <ReportMoney value={marginRevenue} />
          </p>
          {marginNote ? <p className="text-sm text-muted-foreground">{marginNote}</p> : null}
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-sm text-muted-foreground">Production yield</p>
          <p className="text-2xl tabular-nums">{yieldData?.yield_pct != null ? `${yieldData.yield_pct}%` : '—'}</p>
          <p className="text-sm text-muted-foreground">Loss: {yieldData?.loss_total ?? '—'}</p>
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-sm text-muted-foreground">Low stock SKUs</p>
          <p className="text-2xl tabular-nums">{lowStockCount ?? '—'}</p>
        </div>
      </div>

      {receivablesBuckets ? <AgingTable title="Receivables aging" buckets={receivablesBuckets} /> : null}
      {payablesBuckets ? <AgingTable title="Payables aging" buckets={payablesBuckets} /> : null}

      <div className="flex flex-col gap-3">
        <h2 className="font-heading text-lg font-medium">Stock positions</h2>
        <QueryState isLoading={stock.isLoading} error={stock.error ? 'Unable to load stock.' : null}>
          <ResponsiveTable
            data={stockItems}
            getRowKey={(item) => item.sku}
            columns={[
              { key: 'sku', header: 'SKU', primary: true, cell: (item) => item.sku },
              { key: 'product', header: 'Product', cell: (item) => item.product_name },
              {
                key: 'available',
                header: 'Available',
                cell: (item) => <span className="tabular-nums">{item.available_to_sell}</span>,
              },
              {
                key: 'status',
                header: 'Status',
                cell: (item) =>
                  item.is_low_stock ? <Badge variant="destructive">Low stock</Badge> : <Badge variant="outline">OK</Badge>,
              },
            ]}
          />
        </QueryState>
      </div>
    </section>
  )
}
