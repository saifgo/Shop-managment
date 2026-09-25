import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  CreateProductionDialog,
  type ProductionPrefill,
} from '@/features/admin/components/CreateProductionDialog'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { productionApi } from '@/lib/api/production'
import { PERMISSIONS } from '@/lib/auth/permissions'

type ProductionView = 'list' | 'board' | 'demand'

export function AdminProductionPage() {
  const [view, setView] = useState<ProductionView>('list')
  const [createOpen, setCreateOpen] = useState(false)
  const [prefill, setPrefill] = useState<ProductionPrefill | null>(null)
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canManage = can(PERMISSIONS.productionManage)

  const openCreate = (next: ProductionPrefill | null = null) => {
    setPrefill(next)
    setCreateOpen(true)
  }

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'productions'],
    queryFn: () => productionApi.list(),
  })

  const demandQuery = useQuery({
    queryKey: ['admin', 'production-demand'],
    queryFn: () => productionApi.demand(),
    enabled: view === 'demand',
  })

  const startMutation = useMutation({
    mutationFn: (id: string) => productionApi.start(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin', 'productions'] }),
    onError: (err) => toast.error(err.message),
  })

  const boardColumns = buildBoardColumns(data?.items ?? [])

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Production"
        action={
          <>
            <ToggleGroup
              variant="outline"
              spacing={0}
              value={[view]}
              onValueChange={(next) => {
                if (next[0]) setView(next[0] as ProductionView)
              }}
              aria-label="Production view"
            >
              <ToggleGroupItem value="list">List</ToggleGroupItem>
              <ToggleGroupItem value="board">Stage board</ToggleGroupItem>
              <ToggleGroupItem value="demand">Demand planning</ToggleGroupItem>
            </ToggleGroup>
            {canManage ? (
              <Button onClick={() => openCreate()}>
                <PlusIcon data-icon="inline-start" />
                New production
              </Button>
            ) : null}
          </>
        }
      />

      {view === 'list' ? (
        <QueryState
          isLoading={isLoading}
          error={error ? 'Unable to load productions.' : null}
          isEmpty={data?.items.length === 0}
          emptyTitle="No production orders yet"
          emptyDescription="Create a production order to start tracking stages."
          emptyAction={
            canManage ? (
              <Button onClick={() => openCreate()}>
                <PlusIcon data-icon="inline-start" />
                New production
              </Button>
            ) : undefined
          }
        >
          {data ? (
            <ResponsiveTable
              wide
              data={data.items}
              getRowKey={(production) => production.id}
              columns={[
                {
                  key: 'reference',
                  header: 'Reference',
                  primary: true,
                  cell: (production) => (
                    <Link to={`/admin/production/${production.id}`}>{production.reference}</Link>
                  ),
                },
                { key: 'sku', header: 'SKU', cell: (production) => production.sku },
                {
                  key: 'planned',
                  header: 'Planned',
                  cell: (production) => (
                    <span className="tabular-nums">{production.planned_quantity}</span>
                  ),
                },
                {
                  key: 'status',
                  header: 'Status',
                  cell: (production) => <StatusBadge status={production.status} />,
                },
                {
                  key: 'stage',
                  header: 'Current stage',
                  cell: (production) => production.current_stage_name ?? '—',
                },
                {
                  key: 'priority',
                  header: 'Priority',
                  cell: (production) => production.priority,
                },
              ]}
              rowAction={(production) =>
                canManage && (production.status === 'DRAFT' || production.status === 'PLANNED') ? (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={startMutation.isPending}
                    onClick={() => startMutation.mutate(production.id)}
                  >
                    {startMutation.isPending && startMutation.variables === production.id ? (
                      <Spinner data-icon="inline-start" />
                    ) : null}
                    Start
                  </Button>
                ) : null
              }
            />
          ) : null}
        </QueryState>
      ) : null}

      {view === 'board' ? (
        <QueryState
          isLoading={isLoading}
          error={error ? 'Unable to load productions.' : null}
          isEmpty={boardColumns.length === 0}
          emptyTitle="No active jobs"
          emptyDescription="Start a production order to see it on the board."
        >
          <div className="flex gap-4 overflow-x-auto pb-2">
            {boardColumns.map((column) => (
              <Card key={column.name} className="min-w-64 flex-1">
                <CardHeader>
                  <CardTitle>{column.name}</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                  {column.items.map((production) => (
                    <Link
                      key={production.id}
                      to={`/admin/production/${production.id}`}
                      className="flex flex-col gap-2 rounded-lg border border-border p-3"
                    >
                      <span className="font-medium">{production.reference}</span>
                      <span className="text-sm text-muted-foreground">{production.sku}</span>
                      <StatusBadge status={production.status} />
                    </Link>
                  ))}
                  {column.items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No active jobs</p>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </div>
        </QueryState>
      ) : null}

      {view === 'demand' ? (
        <QueryState
          isLoading={demandQuery.isLoading}
          error={demandQuery.error ? 'Unable to load production demand.' : null}
          isEmpty={demandQuery.data?.items.length === 0}
          emptyTitle="No demand to produce"
          emptyDescription="Open orders will appear here when stock cannot cover them."
        >
          {demandQuery.data ? (
            <ResponsiveTable
              wide
              data={demandQuery.data.items}
              getRowKey={(row) => row.variant_id}
              columns={[
                { key: 'product', header: 'Product', primary: true, cell: (row) => row.product_name },
                {
                  key: 'variant',
                  header: 'Variant',
                  cell: (row) => `${row.variant_name} (${row.sku})`,
                },
                {
                  key: 'ordered',
                  header: 'Ordered',
                  cell: (row) => <span className="tabular-nums">{row.ordered}</span>,
                },
                {
                  key: 'reserved',
                  header: 'Reserved',
                  cell: (row) => <span className="tabular-nums">{row.reserved}</span>,
                },
                {
                  key: 'on_hand',
                  header: 'On hand',
                  cell: (row) => <span className="tabular-nums">{row.on_hand}</span>,
                },
                {
                  key: 'net_demand',
                  header: 'Net demand',
                  cell: (row) => <span className="tabular-nums">{row.net_demand}</span>,
                },
                {
                  key: 'in_production',
                  header: 'In production',
                  cell: (row) => <span className="tabular-nums">{row.already_in_production}</span>,
                },
                {
                  key: 'to_produce',
                  header: 'To produce',
                  cell: (row) => <span className="tabular-nums">{row.to_produce}</span>,
                },
              ]}
              rowAction={(row) =>
                canManage ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                      openCreate({
                        variant_id: row.variant_id,
                        label: `${row.product_name} — ${row.variant_name}`,
                        sku: row.sku,
                        quantity: Number(row.to_produce) > 0 ? row.to_produce : undefined,
                      })
                    }
                  >
                    Produce
                  </Button>
                ) : null
              }
            />
          ) : null}
        </QueryState>
      ) : null}

      <CreateProductionDialog open={createOpen} onOpenChange={setCreateOpen} prefill={prefill} />
    </section>
  )
}

function buildBoardColumns(
  productions: Array<{
    id: string
    reference: string
    sku: string | null
    status: string
    current_stage_name?: string | null
  }>,
) {
  const active = productions.filter((p) => ['IN_PROGRESS', 'PAUSED', 'PLANNED'].includes(p.status))
  const stageNames = [...new Set(active.map((p) => p.current_stage_name ?? 'Not started'))]

  return stageNames.map((name) => ({
    name,
    items: active.filter((p) => (p.current_stage_name ?? 'Not started') === name),
  }))
}
