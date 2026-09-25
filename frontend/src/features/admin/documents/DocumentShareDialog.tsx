import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CheckIcon, CopyIcon, ExternalLinkIcon, Link2Icon, Link2OffIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group'
import { Spinner } from '@/components/ui/spinner'
import { documentsApi, sharedDocumentsApi, type CommercialDocument } from '@/lib/api/documents'

interface DocumentShareDialogProps {
  document: CommercialDocument
  onChanged: (document: CommercialDocument) => void
}

/** Creates, copies and revokes the public link customers use to view and download a document. */
export function DocumentShareDialog({ document, onChanged }: DocumentShareDialogProps) {
  const [copied, setCopied] = useState(false)
  const url = document.share_token ? sharedDocumentsApi.pageUrl(document.share_token) : null

  const share = useMutation({
    mutationFn: () => documentsApi.share(document.id),
    onSuccess: onChanged,
    onError: (err) => toast.error(err.message),
  })
  const revoke = useMutation({
    mutationFn: () => documentsApi.unshare(document.id),
    onSuccess: (updated) => {
      onChanged(updated)
      toast.success('Link revoked. It no longer opens the document.')
    },
    onError: (err) => toast.error(err.message),
  })

  const copy = async () => {
    if (!url) return
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error('Copy failed. Select the link and copy it manually.')
    }
  }

  return (
    <Dialog onOpenChange={(open) => (open ? setCopied(false) : undefined)}>
      <DialogTrigger render={<Button variant="outline" />}>
        <Link2Icon data-icon="inline-start" />
        Share
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Share {document.document_number}</DialogTitle>
          <DialogDescription>
            Anyone with the link can view and download this document without signing in.
          </DialogDescription>
        </DialogHeader>

        {url ? (
          <InputGroup>
            <InputGroupInput
              readOnly
              value={url}
              aria-label="Share link"
              onFocus={(event) => event.currentTarget.select()}
            />
            <InputGroupAddon align="inline-end">
              <InputGroupButton size="icon-xs" aria-label="Copy link" onClick={() => void copy()}>
                {copied ? <CheckIcon /> : <CopyIcon />}
              </InputGroupButton>
            </InputGroupAddon>
          </InputGroup>
        ) : (
          <p className="text-sm text-muted-foreground">This document has no link yet.</p>
        )}

        <DialogFooter>
          {url ? (
            <>
              <Button variant="destructive" disabled={revoke.isPending} onClick={() => revoke.mutate()}>
                {revoke.isPending ? <Spinner data-icon="inline-start" /> : <Link2OffIcon data-icon="inline-start" />}
                Revoke link
              </Button>
              <Button nativeButton={false} variant="outline" render={<a href={url} target="_blank" rel="noreferrer" />}>
                <ExternalLinkIcon data-icon="inline-start" />
                Open
              </Button>
              <Button onClick={() => void copy()}>
                {copied ? <CheckIcon data-icon="inline-start" /> : <CopyIcon data-icon="inline-start" />}
                {copied ? 'Copied' : 'Copy link'}
              </Button>
            </>
          ) : (
            <Button disabled={share.isPending} onClick={() => share.mutate()}>
              {share.isPending ? <Spinner data-icon="inline-start" /> : <Link2Icon data-icon="inline-start" />}
              Create link
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
