import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDownIcon, ArrowUpIcon, PlusIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Switch } from '@/components/ui/switch'
import {
  LOSS_REASONS_QUERY_KEY,
  PRODUCTION_STAGES_QUERY_KEY,
  productionConfigApi,
  type ProductionLossReason,
  type ProductionStageConfig,
  type ReconciliationMode,
} from '@/lib/api/production'

const MODES: Array<{ value: ReconciliationMode; label: string; hint: string }> = [
  { value: 'STRICT', label: 'Strict', hint: 'Accepted + loss must equal the input' },
  { value: 'FLEXIBLE', label: 'Flexible', hint: 'Accepted + loss may be less than the input' },
  { value: 'CONVERSION', label: 'Conversion', hint: 'Output counted in its own unit' },
]

/** Production workflow configuration (blueprint §8.1): ordered stages and loss reasons. */
export function AdminProductionSettingsPage() {
  const stages = useQuery({ queryKey: PRODUCTION_STAGES_QUERY_KEY, queryFn: () => productionConfigApi.listStages() })
  const reasons = useQuery({ queryKey: LOSS_REASONS_QUERY_KEY, queryFn: () => productionConfigApi.listLossReasons() })

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/production" className="text-sm text-muted-foreground">
        Production
      </Link>
      <PageHeader
        title="Production workflow"
        description="The stages every production order moves through, and the reasons operators pick for losses."
      />

      <QueryState isLoading={stages.isLoading} error={stages.error ? 'Unable to load stages.' : null}>
        {stages.data ? <StagesCard stages={stages.data.items} /> : null}
      </QueryState>

      <QueryState isLoading={reasons.isLoading} error={reasons.error ? 'Unable to load loss reasons.' : null}>
        {reasons.data ? <LossReasonsCard reasons={reasons.data.items} /> : null}
      </QueryState>
    </section>
  )
}

function StagesCard({ stages }: { stages: ProductionStageConfig[] }) {
  const queryClient = useQueryClient()
  const [newName, setNewName] = useState('')
  const [newMode, setNewMode] = useState<ReconciliationMode>('STRICT')

  const refresh = () => void queryClient.invalidateQueries({ queryKey: PRODUCTION_STAGES_QUERY_KEY })
  const onError = (err: Error) => toast.error(err.message)

  const reorder = useMutation({
    mutationFn: (ids: string[]) => productionConfigApi.reorderStages(ids),
    onSuccess: (result) => queryClient.setQueryData(PRODUCTION_STAGES_QUERY_KEY, result),
    onError,
  })
  const create = useMutation({
    mutationFn: () => productionConfigApi.createStage({ name: newName.trim(), reconciliation_mode: newMode }),
    onSuccess: (stage) => {
      toast.success(`Stage “${stage.name}” added`)
      setNewName('')
      refresh()
    },
    onError,
  })

  const move = (index: number, offset: -1 | 1) => {
    const ids = stages.map((stage) => stage.id)
    const [moved] = ids.splice(index, 1)
    ids.splice(index + offset, 0, moved)
    reorder.mutate(ids)
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (newName.trim()) create.mutate()
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Stages</CardTitle>
        <CardDescription>
          Productions run the active stages in this order. Changes apply to productions started afterwards; running
          productions keep the stages they started with.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {stages.map((stage, index) => (
          <StageRow
            key={`${stage.id}-${stage.name}`}
            stage={stage}
            position={index + 1}
            canMoveUp={index > 0}
            canMoveDown={index < stages.length - 1}
            reordering={reorder.isPending}
            onMove={(offset) => move(index, offset)}
            onSaved={refresh}
          />
        ))}
      </CardContent>
      <CardFooter>
        <form onSubmit={submit} className="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
          <Field className="flex-1">
            <FieldLabel htmlFor="new-stage-name">New stage</FieldLabel>
            <Input
              id="new-stage-name"
              placeholder="e.g. Packing"
              maxLength={128}
              value={newName}
              onChange={(event) => setNewName(event.target.value)}
            />
          </Field>
          <Field className="sm:w-44">
            <FieldLabel htmlFor="new-stage-mode">Reconciliation</FieldLabel>
            <NativeSelect
              id="new-stage-mode"
              className="w-full"
              value={newMode}
              onChange={(event) => setNewMode(event.target.value as ReconciliationMode)}
            >
              {MODES.map((mode) => (
                <NativeSelectOption key={mode.value} value={mode.value}>
                  {mode.label}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          </Field>
          <Button type="submit" disabled={!newName.trim() || create.isPending}>
            {create.isPending ? <Spinner data-icon="inline-start" /> : <PlusIcon data-icon="inline-start" />}
            Add stage
          </Button>
        </form>
      </CardFooter>
    </Card>
  )
}

function StageRow({
  stage,
  position,
  canMoveUp,
  canMoveDown,
  reordering,
  onMove,
  onSaved,
}: {
  stage: ProductionStageConfig
  position: number
  canMoveUp: boolean
  canMoveDown: boolean
  reordering: boolean
  onMove: (offset: -1 | 1) => void
  onSaved: () => void
}) {
  const [name, setName] = useState(stage.name)
  const update = useMutation({
    mutationFn: (payload: Parameters<typeof productionConfigApi.updateStage>[1]) =>
      productionConfigApi.updateStage(stage.id, payload),
    onSuccess: onSaved,
    onError: (err) => {
      toast.error(err.message)
      setName(stage.name)
    },
  })

  const saveName = () => {
    const trimmed = name.trim()
    if (!trimmed) {
      setName(stage.name)
      return
    }
    if (trimmed !== stage.name) update.mutate({ name: trimmed })
  }

  const id = `stage-${stage.id}`
  const mode = MODES.find((candidate) => candidate.value === stage.reconciliation_mode)

  return (
    <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center">
      <div className="flex items-center gap-2 lg:w-72">
        <span className="w-6 text-right text-sm text-muted-foreground tabular-nums">{position}</span>
        <Input
          aria-label={`Name of stage ${position}`}
          value={name}
          maxLength={128}
          onChange={(event) => setName(event.target.value)}
          onBlur={saveName}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault()
              saveName()
            }
          }}
        />
        {!stage.is_active ? <Badge variant="secondary">Inactive</Badge> : null}
      </div>

      <NativeSelect
        aria-label={`Reconciliation for ${stage.name}`}
        title={mode?.hint}
        value={stage.reconciliation_mode}
        disabled={update.isPending}
        onChange={(event) => update.mutate({ reconciliation_mode: event.target.value as ReconciliationMode })}
      >
        {MODES.map((candidate) => (
          <NativeSelectOption key={candidate.value} value={candidate.value}>
            {candidate.label}
          </NativeSelectOption>
        ))}
      </NativeSelect>

      <div className="flex flex-wrap items-center gap-4 lg:flex-1">
        <Field orientation="horizontal" className="w-auto">
          <Checkbox
            id={`${id}-qty`}
            checked={stage.can_record_quantity}
            disabled={update.isPending}
            onCheckedChange={(checked) => update.mutate({ can_record_quantity: checked === true })}
          />
          <FieldLabel htmlFor={`${id}-qty`}>Records quantities</FieldLabel>
        </Field>
        <Field orientation="horizontal" className="w-auto">
          <Checkbox
            id={`${id}-loss`}
            checked={stage.can_record_loss}
            disabled={update.isPending || !stage.can_record_quantity}
            onCheckedChange={(checked) => update.mutate({ can_record_loss: checked === true })}
          />
          <FieldLabel htmlFor={`${id}-loss`}>Records losses</FieldLabel>
        </Field>
        <Field orientation="horizontal" className="w-auto">
          <Switch
            id={`${id}-active`}
            checked={stage.is_active}
            disabled={update.isPending}
            onCheckedChange={(checked) => update.mutate({ is_active: checked })}
          />
          <FieldLabel htmlFor={`${id}-active`}>Active</FieldLabel>
        </Field>
      </div>

      <div className="flex gap-1">
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Move ${stage.name} up`}
          disabled={!canMoveUp || reordering}
          onClick={() => onMove(-1)}
        >
          <ArrowUpIcon />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Move ${stage.name} down`}
          disabled={!canMoveDown || reordering}
          onClick={() => onMove(1)}
        >
          <ArrowDownIcon />
        </Button>
      </div>
    </div>
  )
}

function LossReasonsCard({ reasons }: { reasons: ProductionLossReason[] }) {
  const queryClient = useQueryClient()
  const [label, setLabel] = useState('')
  const refresh = () => void queryClient.invalidateQueries({ queryKey: LOSS_REASONS_QUERY_KEY })

  const create = useMutation({
    mutationFn: () => productionConfigApi.createLossReason({ label: label.trim() }),
    onSuccess: (reason) => {
      toast.success(`Loss reason “${reason.label}” added`)
      setLabel('')
      refresh()
    },
    onError: (err) => toast.error(err.message),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>Loss reasons</CardTitle>
        <CardDescription>
          Every lost unit must be explained with one of the active reasons. Inactive reasons stay on past records.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col divide-y divide-border">
        {reasons.map((reason) => (
          <LossReasonRow key={`${reason.id}-${reason.label}`} reason={reason} onSaved={refresh} />
        ))}
      </CardContent>
      <CardFooter>
        <form
          className="flex w-full flex-col gap-3 sm:flex-row sm:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            if (label.trim()) create.mutate()
          }}
        >
          <Field className="flex-1">
            <FieldLabel htmlFor="new-loss-reason">New loss reason</FieldLabel>
            <Input
              id="new-loss-reason"
              placeholder="e.g. Glaze defect"
              maxLength={128}
              value={label}
              onChange={(event) => setLabel(event.target.value)}
            />
          </Field>
          <Button type="submit" disabled={!label.trim() || create.isPending}>
            {create.isPending ? <Spinner data-icon="inline-start" /> : <PlusIcon data-icon="inline-start" />}
            Add reason
          </Button>
        </form>
      </CardFooter>
    </Card>
  )
}

function LossReasonRow({ reason, onSaved }: { reason: ProductionLossReason; onSaved: () => void }) {
  const [label, setLabel] = useState(reason.label)
  const update = useMutation({
    mutationFn: (payload: Parameters<typeof productionConfigApi.updateLossReason>[1]) =>
      productionConfigApi.updateLossReason(reason.id, payload),
    onSuccess: onSaved,
    onError: (err) => {
      toast.error(err.message)
      setLabel(reason.label)
    },
  })

  const saveLabel = () => {
    const trimmed = label.trim()
    if (!trimmed) {
      setLabel(reason.label)
      return
    }
    if (trimmed !== reason.label) update.mutate({ label: trimmed })
  }

  return (
    <div className="flex items-center gap-3 py-2">
      <Input
        aria-label={`Label of ${reason.code}`}
        className="max-w-xs"
        value={label}
        maxLength={128}
        onChange={(event) => setLabel(event.target.value)}
        onBlur={saveLabel}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault()
            saveLabel()
          }
        }}
      />
      <code className="hidden text-xs text-muted-foreground sm:inline">{reason.code}</code>
      <Field orientation="horizontal" className="ml-auto w-auto">
        <Switch
          id={`reason-${reason.id}`}
          checked={reason.is_active}
          disabled={update.isPending}
          onCheckedChange={(checked) => update.mutate({ is_active: checked })}
        />
        <FieldLabel htmlFor={`reason-${reason.id}`}>Active</FieldLabel>
      </Field>
    </div>
  )
}
