import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PauseIcon, PlayIcon } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useAuth } from '@/features/auth/hooks/useAuth'
import {
  LOSS_REASONS_QUERY_KEY,
  productionApi,
  productionConfigApi,
  type ProductionDetail,
  type ProductionLossReason,
  type ProductionStage,
} from '@/lib/api/production'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatDateTime, formatQuantity } from '@/lib/format'
import { DECIMAL, fromUnits, toUnits } from '@/lib/quantity'

const MODE_HINT: Record<string, string> = {
  STRICT: 'Accepted + loss must equal what went in.',
  FLEXIBLE: 'Accepted + loss may be less than what went in.',
  CONVERSION: 'Output is counted in its own unit.',
}

export function AdminProductionDetailPage() {
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canManage = can(PERMISSIONS.productionManage)
  const canExecute = can(PERMISSIONS.productionStageExecute)
  const [activeStageId, setActiveStageId] = useState<string | null>(null)
  const [breakdownStageId, setBreakdownStageId] = useState<string | null>(null)
  const [cancelOpen, setCancelOpen] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'production', id],
    queryFn: () => productionApi.get(id),
    enabled: Boolean(id),
  })

  const lossReasons = useQuery({
    queryKey: LOSS_REASONS_QUERY_KEY,
    queryFn: () => productionConfigApi.listLossReasons(),
    enabled: canExecute,
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'production', id] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'productions'] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'production-demand'] })
  }
  const onError = (err: Error) => toast.error(err.message)

  const lifecycle = useMutation({
    mutationFn: (action: 'plan' | 'start' | 'pause' | 'resume') => productionApi[action](id),
    onSuccess: invalidate,
    onError,
  })
  const startStageMutation = useMutation({
    mutationFn: (stageId: string) => productionApi.startStage(id, stageId),
    onSuccess: invalidate,
    onError,
  })

  const activeStage = data?.stages.find((stage) => stage.id === activeStageId && stage.status === 'IN_PROGRESS') ?? null
  const breakdownStage = data?.stages.find((stage) => stage.id === breakdownStageId) ?? null
  const multiProduct = (data?.items.length ?? 0) > 1
  const running = data?.status === 'IN_PROGRESS'
  const nextStage = data?.stages.find((stage) => stage.status !== 'COMPLETED')
  const lifecycleButton = (action: 'plan' | 'start' | 'pause' | 'resume', label: string, variant: 'default' | 'outline') => (
    <Button variant={variant} disabled={lifecycle.isPending} onClick={() => lifecycle.mutate(action)}>
      {lifecycle.isPending && lifecycle.variables === action ? (
        <Spinner data-icon="inline-start" />
      ) : action === 'pause' ? (
        <PauseIcon data-icon="inline-start" />
      ) : action === 'plan' ? null : (
        <PlayIcon data-icon="inline-start" />
      )}
      {label}
    </Button>
  )

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/production" className="text-sm text-muted-foreground">
        Production
      </Link>

      <QueryState
        isLoading={isLoading}
        error={error || (!isLoading && !data) ? 'Unable to load production order.' : null}
      >
        {data ? (
          <>
            <PageHeader
              title={data.reference}
              description={describeProducts(data)}
              action={
                <>
                  <StatusBadge status={data.status} />
                  {canManage && data.can_plan ? lifecycleButton('plan', 'Plan', 'outline') : null}
                  {canManage && data.can_start ? lifecycleButton('start', 'Start production', 'default') : null}
                  {canManage && data.can_resume ? lifecycleButton('resume', 'Resume', 'default') : null}
                  {canManage && data.can_pause ? lifecycleButton('pause', 'Pause', 'outline') : null}
                  {canManage && data.can_cancel ? (
                    <Button variant="destructive" onClick={() => setCancelOpen(true)}>
                      Cancel
                    </Button>
                  ) : null}
                </>
              }
            />

            {data.status === 'PAUSED' ? (
              <Alert>
                <PauseIcon />
                <AlertTitle>Production is paused</AlertTitle>
                <AlertDescription>Resume it to start or complete stages.</AlertDescription>
              </Alert>
            ) : null}

            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Priority</p>
                <p>{data.priority}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Current stage</p>
                <p>{data.current_stage_name ?? '—'}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Planned{multiProduct ? ' (total)' : ''}</p>
                <p className="tabular-nums">{formatQuantity(data.planned_quantity)}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">
                  {data.status === 'COMPLETED' ? 'Final output' : 'In process'}
                  {multiProduct ? ' (total)' : ''}
                </p>
                <p className="tabular-nums">
                  {formatQuantity(
                    fromUnits(
                      sumUnits(
                        data.items.map((item) =>
                          data.status === 'COMPLETED' ? item.accepted_output_quantity : item.in_process_quantity,
                        ),
                      ),
                    ),
                  )}
                </p>
              </div>
            </div>

            <Card>
              <CardHeader>
                <CardTitle>Products</CardTitle>
                <CardDescription>
                  {multiProduct
                    ? `${data.items.length} products move through the stages together.`
                    : 'The product made by this run.'}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ResponsiveTable
                  data={data.items}
                  getRowKey={(item) => item.id}
                  columns={[
                    {
                      key: 'product',
                      header: 'Product',
                      primary: true,
                      cell: (item) => `${item.product_name} — ${item.variant_name}`,
                    },
                    { key: 'sku', header: 'SKU', mobile: true, cell: (item) => item.sku },
                    {
                      key: 'planned',
                      header: 'Planned',
                      mobile: true,
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(item.planned_quantity),
                    },
                    {
                      key: 'in_process',
                      header: 'In process',
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(item.in_process_quantity),
                    },
                    {
                      key: 'lost',
                      header: 'Lost',
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(item.loss_quantity),
                    },
                    {
                      key: 'output',
                      header: 'Received into stock',
                      className: 'tabular-nums',
                      cell: (item) => (data.status === 'COMPLETED' ? formatQuantity(item.accepted_output_quantity) : '—'),
                    },
                  ]}
                />
              </CardContent>
            </Card>

            {data.stages.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                Stages are created from the production workflow when the order is started.
              </p>
            ) : (
              <ResponsiveTable
                wide
                data={data.stages}
                getRowKey={(stage) => stage.execution_id}
                columns={[
                  {
                    key: 'sequence',
                    header: '#',
                    cell: (stage) => <span className="tabular-nums">{stage.sequence}</span>,
                  },
                  { key: 'name', header: 'Stage', primary: true, cell: (stage) => stage.name },
                  {
                    key: 'status',
                    header: 'Status',
                    mobile: true,
                    cell: (stage) => <StatusBadge status={stage.status} />,
                  },
                  {
                    key: 'input',
                    header: multiProduct ? 'Input (total)' : 'Input',
                    cell: (stage) => <span className="tabular-nums">{formatQuantity(stage.input_quantity)}</span>,
                  },
                  {
                    key: 'accepted',
                    header: multiProduct ? 'Accepted (total)' : 'Accepted',
                    cell: (stage) => (
                      <span className="tabular-nums">{formatQuantity(stage.accepted_output_quantity)}</span>
                    ),
                  },
                  {
                    key: 'loss',
                    header: multiProduct ? 'Loss (total)' : 'Loss',
                    cell: (stage) => <span className="tabular-nums">{formatQuantity(stage.loss_quantity)}</span>,
                  },
                  {
                    key: 'by',
                    header: 'Done by',
                    cell: (stage) =>
                      stage.performed_by_name ? (
                        <span className="flex flex-col">
                          <span>{stage.performed_by_name}</span>
                          <span className="text-xs text-muted-foreground">
                            {formatDateTime(stage.completed_at ?? stage.started_at)}
                          </span>
                        </span>
                      ) : (
                        '—'
                      ),
                  },
                ]}
                rowAction={(stage) => (
                  <div className="flex justify-end gap-2">
                    {canExecute && running && stage.status === 'PENDING' && stage.id === nextStage?.id ? (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={startStageMutation.isPending}
                        onClick={() => startStageMutation.mutate(stage.id)}
                      >
                        {startStageMutation.isPending ? <Spinner data-icon="inline-start" /> : null}
                        Start
                      </Button>
                    ) : null}
                    {canExecute && running && stage.status === 'IN_PROGRESS' ? (
                      <Button size="sm" onClick={() => setActiveStageId(stage.id)}>
                        Complete
                      </Button>
                    ) : null}
                    {stage.status === 'COMPLETED' ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setBreakdownStageId(breakdownStageId === stage.id ? null : stage.id)}
                      >
                        {breakdownStageId === stage.id ? 'Hide' : 'Details'}
                      </Button>
                    ) : null}
                  </div>
                )}
              />
            )}

            {breakdownStage ? <StageBreakdown stage={breakdownStage} onClose={() => setBreakdownStageId(null)} /> : null}

            {activeStage && running && canExecute ? (
              <QueryState
                isLoading={lossReasons.isLoading}
                error={lossReasons.error ? 'Unable to load loss reasons.' : null}
              >
                {lossReasons.data ? (
                  // Keyed so the form resets when switching stages.
                  <CompleteStageForm
                    key={activeStage.id}
                    productionId={id}
                    stage={activeStage}
                    lossReasons={lossReasons.data.items.filter((reason) => reason.is_active)}
                    onDone={() => {
                      setActiveStageId(null)
                      invalidate()
                    }}
                    onCancel={() => setActiveStageId(null)}
                  />
                ) : null}
              </QueryState>
            ) : null}

            <CancelProductionDialog
              open={cancelOpen}
              onOpenChange={setCancelOpen}
              production={data}
              onCancelled={invalidate}
            />
          </>
        ) : null}
      </QueryState>
    </section>
  )
}

function CancelProductionDialog({
  open,
  onOpenChange,
  production,
  onCancelled,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  production: ProductionDetail
  onCancelled: () => void
}) {
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => productionApi.cancel(production.id, reason.trim() || undefined),
    onSuccess: () => {
      toast.success(`${production.reference} cancelled`)
      onOpenChange(false)
      setReason('')
      onCancelled()
    },
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cancel {production.reference}?</DialogTitle>
          <DialogDescription>
            This cannot be undone. Units still in process are not added to stock; completed stages stay in the history.
          </DialogDescription>
        </DialogHeader>
        <Field>
          <FieldLabel htmlFor="cancel-reason">Reason</FieldLabel>
          <Textarea id="cancel-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          <FieldDescription>Recorded in the audit log.</FieldDescription>
        </Field>
        {cancel.error ? <FieldError>{cancel.error.message}</FieldError> : null}
        <DialogFooter>
          <Button variant="outline" disabled={cancel.isPending} onClick={() => onOpenChange(false)}>
            Keep production
          </Button>
          <Button variant="destructive" disabled={cancel.isPending} onClick={() => cancel.mutate()}>
            {cancel.isPending ? <Spinner data-icon="inline-start" /> : null}
            Cancel production
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface LineDraft {
  item_id: string
  label: string
  sku: string
  input: string
  accepted: string
  loss: string
  reason: string
}

function CompleteStageForm({
  productionId,
  stage,
  lossReasons,
  onDone,
  onCancel,
}: {
  productionId: string
  stage: ProductionStage
  lossReasons: ProductionLossReason[]
  onDone: () => void
  onCancel: () => void
}) {
  const recordsQuantity = stage.can_record_quantity
  const recordsLoss = stage.can_record_loss && recordsQuantity
  const [lines, setLines] = useState<LineDraft[]>(() =>
    stage.lines.map((line) => ({
      item_id: line.item_id,
      label: `${line.product_name} — ${line.variant_name}`,
      sku: line.sku,
      input: line.input_quantity,
      accepted: line.input_quantity,
      loss: '0',
      reason: lossReasons[0]?.code ?? '',
    })),
  )
  const [notes, setNotes] = useState('')
  const [showErrors, setShowErrors] = useState(false)

  const update = (itemId: string, patch: Partial<LineDraft>) =>
    setLines((current) => current.map((line) => (line.item_id === itemId ? { ...line, ...patch } : line)))

  /** In strict / flexible stages, entering a loss moves the rest to accepted output. */
  const updateLoss = (line: LineDraft, loss: string) => {
    const patch: Partial<LineDraft> = { loss }
    if (stage.reconciliation_mode !== 'CONVERSION' && DECIMAL.test(loss.trim())) {
      const rest = toUnits(line.input) - toUnits(loss)
      if (rest >= 0) patch.accepted = fromUnits(rest)
    }
    update(line.item_id, patch)
  }

  const hasLoss = (line: LineDraft) => DECIMAL.test((line.loss || '0').trim()) && toUnits(line.loss || '0') > 0

  const lineError = (line: LineDraft): string | null => {
    if (!recordsQuantity) return null
    if (!DECIMAL.test(line.accepted.trim()) || !DECIMAL.test((line.loss || '0').trim())) {
      return 'Enter quantities of 0 or more (up to 4 decimals).'
    }
    const sum = toUnits(line.accepted) + toUnits(line.loss || '0')
    if (stage.reconciliation_mode === 'STRICT' && sum !== toUnits(line.input)) {
      return `Accepted + loss must equal the input (${formatQuantity(line.input)}).`
    }
    if (stage.reconciliation_mode === 'FLEXIBLE' && sum > toUnits(line.input)) {
      return `Accepted + loss cannot exceed the input (${formatQuantity(line.input)}).`
    }
    if (hasLoss(line) && !line.reason) {
      return 'Choose a loss reason.'
    }
    return null
  }

  const complete = useMutation({
    mutationFn: () =>
      productionApi.completeStage(productionId, stage.id, {
        notes: notes.trim() || undefined,
        items: recordsQuantity
          ? lines.map((line) => {
              const loss = fromUnits(toUnits(line.loss || '0'))
              return {
                item_id: line.item_id,
                accepted_output_quantity: fromUnits(toUnits(line.accepted)),
                loss_quantity: loss,
                losses: toUnits(loss) > 0 ? [{ reason_code: line.reason, quantity: loss }] : undefined,
              }
            })
          : [],
      }),
    onSuccess: () => {
      toast.success(`${stage.name} completed`)
      onDone()
    },
  })

  const hasErrors = lines.some((line) => lineError(line) !== null)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Complete {stage.name}</CardTitle>
        <CardDescription>
          {recordsQuantity
            ? `Record what came out of this stage for each product. ${MODE_HINT[stage.reconciliation_mode] ?? ''}`
            : 'This stage does not record quantities: everything that went in moves on to the next stage.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          id="complete-stage-form"
          className="flex flex-col gap-5"
          onSubmit={(event) => {
            event.preventDefault()
            setShowErrors(true)
            if (!hasErrors) complete.mutate()
          }}
        >
          <div className="overflow-x-auto [contain:inline-size]">
            <Table className="min-w-[44rem]">
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead className="text-right">Input</TableHead>
                  <TableHead>Accepted</TableHead>
                  <TableHead>Loss</TableHead>
                  <TableHead>Loss reason</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => {
                  const error = showErrors ? lineError(line) : null

                  return (
                    <TableRow key={line.item_id}>
                      <TableCell className="align-top">
                        <div className="flex flex-col">
                          <span className="font-medium">{line.label}</span>
                          <span className="text-xs text-muted-foreground">{line.sku}</span>
                          {error ? <FieldError className="mt-1">{error}</FieldError> : null}
                        </div>
                      </TableCell>
                      <TableCell className="text-right align-top tabular-nums">{formatQuantity(line.input)}</TableCell>
                      <TableCell className="align-top">
                        <Input
                          aria-label={`Accepted output for ${line.sku}`}
                          inputMode="decimal"
                          className="w-24 tabular-nums"
                          value={line.accepted}
                          disabled={!recordsQuantity}
                          aria-invalid={error ? true : undefined}
                          onChange={(event) => update(line.item_id, { accepted: event.target.value })}
                        />
                      </TableCell>
                      <TableCell className="align-top">
                        <Input
                          aria-label={`Loss for ${line.sku}`}
                          inputMode="decimal"
                          className="w-24 tabular-nums"
                          value={line.loss}
                          disabled={!recordsLoss}
                          aria-invalid={error ? true : undefined}
                          onChange={(event) => updateLoss(line, event.target.value)}
                        />
                      </TableCell>
                      <TableCell className="align-top">
                        <NativeSelect
                          aria-label={`Loss reason for ${line.sku}`}
                          value={line.reason}
                          disabled={!recordsLoss || !hasLoss(line)}
                          onChange={(event) => update(line.item_id, { reason: event.target.value })}
                        >
                          {lossReasons.map((reason) => (
                            <NativeSelectOption key={reason.id} value={reason.code}>
                              {reason.label}
                            </NativeSelectOption>
                          ))}
                        </NativeSelect>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          {recordsQuantity && !stage.can_record_loss ? (
            <p className="text-sm text-muted-foreground">Losses are not recorded at this stage.</p>
          ) : null}

          <Field>
            <FieldLabel htmlFor="stage-notes">Notes</FieldLabel>
            <Textarea id="stage-notes" value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} />
            <FieldDescription>Optional notes for this stage completion.</FieldDescription>
          </Field>

          {complete.error ? <FieldError>{complete.error.message}</FieldError> : null}
        </form>
      </CardContent>
      <CardFooter className="flex gap-2">
        <Button type="submit" form="complete-stage-form" disabled={complete.isPending}>
          {complete.isPending ? <Spinner data-icon="inline-start" /> : null}
          Record completion
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
      </CardFooter>
    </Card>
  )
}

function StageBreakdown({ stage, onClose }: { stage: ProductionStage; onClose: () => void }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{stage.name}</CardTitle>
        <CardDescription>
          {stage.performed_by_name ? `By ${stage.performed_by_name}` : null}
          {stage.completed_at ? ` · completed ${formatDateTime(stage.completed_at)}` : null}
          {stage.notes ? ` · ${stage.notes}` : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ResponsiveTable
          data={stage.lines}
          getRowKey={(line) => line.id}
          columns={[
            {
              key: 'product',
              header: 'Product',
              primary: true,
              cell: (line) => `${line.product_name} — ${line.variant_name} (${line.sku})`,
            },
            {
              key: 'input',
              header: 'Input',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.input_quantity),
            },
            {
              key: 'accepted',
              header: 'Accepted',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.accepted_output_quantity),
            },
            {
              key: 'loss',
              header: 'Loss',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.loss_quantity),
            },
            {
              key: 'reasons',
              header: 'Loss reasons',
              cell: (line) =>
                line.losses.length > 0
                  ? line.losses
                      .map((loss) => `${loss.reason_label ?? loss.reason_code} ×${formatQuantity(loss.quantity)}`)
                      .join(', ')
                  : '—',
            },
          ]}
        />
      </CardContent>
      <CardFooter>
        <Button variant="outline" size="sm" onClick={onClose}>
          Close
        </Button>
      </CardFooter>
    </Card>
  )
}

function describeProducts(data: ProductionDetail): string {
  const [first] = data.items
  if (!first) return ''
  if (data.items.length === 1) return `${first.product_name} — ${first.variant_name} (${first.sku})`
  return `${data.items.length} products: ${data.items.map((item) => item.sku).join(', ')}`
}

function sumUnits(values: string[]): number {
  return values.reduce((total, value) => total + toUnits(value), 0)
}
