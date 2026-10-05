import { useQuery } from '@tanstack/react-query'
import { AlertCircleIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { formatQuantity, formatWithUnit } from '@/lib/format'
import { reportsApi, type AgingBuckets, type ReportMoney } from '@/lib/api/reports'

function ReportMoneyText({ value }: { value: ReportMoney | null | undefined }) {
  if (!value) return '—'
  return <MoneyText amount={value.amount} currency={value.currency} />
}

function Kpi({ label, children, hint }: { label: string; children: React.ReactNode; hint?: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-2xl tabular-nums">{children}</p>
      {hint ? <p className="text-sm text-muted-foreground">{hint}</p> : null}
    </div>
  )
}

function AgingTable({ title, buckets }: { title: string; buckets: AgingBuckets }) {
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

  const stockItems = stock.data?.items ?? []
  const marginData = margin.data
  const yieldData = yieldReport.data
  const uncosted = Number(marginData?.uncosted_units ?? 0)

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Operational reports" />

      <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
        <Kpi label="Sales orders" hint={<ReportMoneyText value={sales.data?.revenue} />}>
          {sales.data?.order_count ?? '—'}
        </Kpi>
        <Kpi label="Invoiced revenue" hint="Before VAT, net of credit notes">
          <ReportMoneyText value={marginData?.revenue} />
        </Kpi>
        <Kpi
          label="Gross margin"
          hint={
            marginData?.margin_pct != null ? (
              <>
                {marginData.margin_pct}% · cost <ReportMoneyText value={marginData.cost} />
              </>
            ) : (
              'No invoiced revenue yet'
            )
          }
        >
          <ReportMoneyText value={marginData?.margin} />
        </Kpi>
        <Kpi
          label="Production yield"
          hint={
            yieldData
              ? `${formatQuantity(yieldData.output_total)} good of ${formatQuantity(yieldData.input_total)} started`
              : undefined
          }
        >
          {yieldData?.yield_pct != null ? `${yieldData.yield_pct}%` : '—'}
        </Kpi>
        <Kpi label="Stock value" hint="At average cost">
          {stock.data ? <MoneyText amount={stock.data.total_stock_value} currency={stock.data.currency} /> : '—'}
        </Kpi>
        <Kpi label="Finished goods" hint="Stock value">
          {stock.data ? <MoneyText amount={stock.data.finished_goods_value} currency={stock.data.currency} /> : '—'}
        </Kpi>
        <Kpi label="Raw materials" hint="Stock value">
          {stock.data ? <MoneyText amount={stock.data.raw_materials_value} currency={stock.data.currency} /> : '—'}
        </Kpi>
        <Kpi label="Low stock SKUs">{stock.data?.low_stock_count ?? '—'}</Kpi>
      </div>

      {uncosted > 0 ? (
        <Alert>
          <AlertCircleIcon />
          <AlertTitle>The margin is overstated</AlertTitle>
          <AlertDescription>
            {formatQuantity(marginData?.uncosted_units)} shipped units have no known cost. Give their products a recipe,
            receive them through a purchase order, or record opening stock with a cost.
          </AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-3">
        <h2 className="font-heading text-lg font-medium">Where pieces are lost</h2>
        <QueryState
          isLoading={yieldReport.isLoading}
          error={yieldReport.error ? 'Unable to load production yield.' : null}
          isEmpty={!!yieldData && yieldData.by_stage.length === 0}
          emptyTitle="No finished stages yet"
          emptyDescription="Losses by stage and reason appear once production stages are completed."
        >
          <div className="grid gap-6 lg:grid-cols-2">
            <ResponsiveTable
              data={yieldData?.by_stage ?? []}
              getRowKey={(row) => `${row.sequence}-${row.stage}`}
              columns={[
                { key: 'stage', header: 'Stage', primary: true, cell: (row) => row.stage },
                {
                  key: 'input',
                  header: 'In',
                  mobile: true,
                  className: 'tabular-nums',
                  cell: (row) => formatQuantity(row.input),
                },
                {
                  key: 'loss',
                  header: 'Lost',
                  mobile: true,
                  className: 'tabular-nums',
                  cell: (row) => formatQuantity(row.loss),
                },
                {
                  key: 'loss_pct',
                  header: 'Loss rate',
                  mobile: true,
                  className: 'tabular-nums',
                  cell: (row) =>
                    row.loss_pct === null ? (
                      '—'
                    ) : Number(row.loss_pct) >= 10 ? (
                      <Badge variant="error">{row.loss_pct}%</Badge>
                    ) : (
                      `${row.loss_pct}%`
                    ),
                },
              ]}
            />
            <ResponsiveTable
              data={yieldData?.by_reason ?? []}
              getRowKey={(row) => row.reason_code}
              columns={[
                { key: 'reason', header: 'Reason', primary: true, cell: (row) => row.reason_label },
                {
                  key: 'quantity',
                  header: 'Pieces',
                  mobile: true,
                  className: 'tabular-nums',
                  cell: (row) => formatQuantity(row.quantity),
                },
              ]}
            />
          </div>
        </QueryState>
      </div>

      {receivables.data ? <AgingTable title="Receivables aging" buckets={receivables.data.receivables_aging} /> : null}
      {payables.data ? <AgingTable title="Payables aging" buckets={payables.data.payables_aging} /> : null}

      <div className="flex flex-col gap-3">
        <h2 className="font-heading text-lg font-medium">Stock positions</h2>
        <QueryState isLoading={stock.isLoading} error={stock.error ? 'Unable to load stock.' : null}>
          <ResponsiveTable
            data={stockItems}
            getRowKey={(item) => item.variant_id}
            columns={[
              { key: 'sku', header: 'SKU', primary: true, cell: (item) => item.sku },
              {
                key: 'product',
                header: 'Product',
                cell: (item) => (
                  <span className="flex flex-wrap items-center gap-2">
                    {item.product_name}
                    {item.kind === 'raw_material' ? <Badge variant="outline">Raw material</Badge> : null}
                  </span>
                ),
              },
              {
                key: 'available',
                header: 'Available',
                cell: (item) => <span className="tabular-nums">{formatWithUnit(item.available_to_sell, item.unit, 4)}</span>,
              },
              {
                key: 'average_cost',
                header: 'Avg cost',
                cell: (item) =>
                  Number(item.average_cost) > 0 ? (
                    <MoneyText amount={item.average_cost} currency={stock.data?.currency} />
                  ) : (
                    <span className="text-muted-foreground">Unknown</span>
                  ),
              },
              {
                key: 'value',
                header: 'Value',
                cell: (item) => <MoneyText amount={item.stock_value} currency={stock.data?.currency} />,
              },
              {
                key: 'status',
                header: 'Status',
                cell: (item) =>
                  item.is_low_stock ? <Badge variant="warning">Low stock</Badge> : <Badge variant="outline">OK</Badge>,
              },
            ]}
          />
        </QueryState>
      </div>
    </section>
  )
}
