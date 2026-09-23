import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { customersApi, type CustomerDetail } from '@/lib/api/customers'
import { AlertCircleIcon } from 'lucide-react'

export function PortalAccountPage() {
  const { data: profile, isLoading, error } = useQuery({
    queryKey: ['portal', 'profile'],
    queryFn: () => customersApi.getProfile(),
  })

  return (
    <QueryState
      isLoading={isLoading}
      error={error || (!isLoading && !profile) ? 'Unable to load account profile.' : null}
    >
      {profile ? <AccountForm key={profile.id} profile={profile} /> : null}
    </QueryState>
  )
}

function AccountForm({ profile }: { profile: CustomerDetail }) {
  const queryClient = useQueryClient()

  const [displayName, setDisplayName] = useState(profile.display_name)
  const [contacts, setContacts] = useState(
    profile.contacts.map((contact) => ({
      id: contact.id,
      name: contact.name,
      email: contact.email ?? '',
      phone: contact.phone ?? '',
    })),
  )
  const [addresses, setAddresses] = useState(
    profile.addresses.map((address) => ({
      id: address.id,
      line1: address.line1,
      line2: address.line2 ?? '',
      city: address.city,
      postal_code: address.postal_code,
      country: address.country,
    })),
  )

  const saveProfile = useMutation({
    mutationFn: () =>
      customersApi.updateProfile({
        display_name: displayName,
        contacts,
        addresses,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['portal', 'profile'] })
    },
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Account" description="View and update your customer profile, contacts, and addresses." />

      {saveProfile.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>Your profile could not be saved. Try again.</AlertDescription>
        </Alert>
      ) : null}

      <Card className="max-w-xl">
        <CardHeader>
          <CardTitle>Profile</CardTitle>
        </CardHeader>
        <CardContent>
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor="display-name">Display name</FieldLabel>
              <Input id="display-name" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
            </Field>

            <h3 className="font-heading text-sm font-medium">Primary contact</h3>
            {contacts.slice(0, 1).map((contact) => (
              <FieldGroup key={contact.id}>
                <Field>
                  <FieldLabel htmlFor={`contact-name-${contact.id}`}>Name</FieldLabel>
                  <Input
                    id={`contact-name-${contact.id}`}
                    value={contact.name}
                    onChange={(e) =>
                      setContacts(contacts.map((item) => (item.id === contact.id ? { ...item, name: e.target.value } : item)))
                    }
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor={`contact-email-${contact.id}`}>Email</FieldLabel>
                  <Input
                    id={`contact-email-${contact.id}`}
                    value={contact.email}
                    onChange={(e) =>
                      setContacts(
                        contacts.map((item) => (item.id === contact.id ? { ...item, email: e.target.value } : item)),
                      )
                    }
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor={`contact-phone-${contact.id}`}>Phone</FieldLabel>
                  <Input
                    id={`contact-phone-${contact.id}`}
                    value={contact.phone}
                    onChange={(e) =>
                      setContacts(
                        contacts.map((item) => (item.id === contact.id ? { ...item, phone: e.target.value } : item)),
                      )
                    }
                  />
                </Field>
              </FieldGroup>
            ))}

            <h3 className="font-heading text-sm font-medium">Default address</h3>
            {addresses.slice(0, 1).map((address) => (
              <FieldGroup key={address.id}>
                <Field>
                  <FieldLabel htmlFor={`address-line1-${address.id}`}>Line 1</FieldLabel>
                  <Input
                    id={`address-line1-${address.id}`}
                    value={address.line1}
                    onChange={(e) =>
                      setAddresses(
                        addresses.map((item) => (item.id === address.id ? { ...item, line1: e.target.value } : item)),
                      )
                    }
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor={`address-line2-${address.id}`}>Line 2</FieldLabel>
                  <Input
                    id={`address-line2-${address.id}`}
                    value={address.line2}
                    onChange={(e) =>
                      setAddresses(
                        addresses.map((item) => (item.id === address.id ? { ...item, line2: e.target.value } : item)),
                      )
                    }
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor={`address-city-${address.id}`}>City</FieldLabel>
                  <Input
                    id={`address-city-${address.id}`}
                    value={address.city}
                    onChange={(e) =>
                      setAddresses(
                        addresses.map((item) => (item.id === address.id ? { ...item, city: e.target.value } : item)),
                      )
                    }
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor={`address-postal-${address.id}`}>Postal code</FieldLabel>
                  <Input
                    id={`address-postal-${address.id}`}
                    value={address.postal_code}
                    onChange={(e) =>
                      setAddresses(
                        addresses.map((item) =>
                          item.id === address.id ? { ...item, postal_code: e.target.value } : item,
                        ),
                      )
                    }
                  />
                </Field>
              </FieldGroup>
            ))}
          </FieldGroup>
        </CardContent>
        <CardFooter>
          <Button onClick={() => saveProfile.mutate()} disabled={saveProfile.isPending}>
            {saveProfile.isPending ? <Spinner data-icon="inline-start" /> : null}
            {saveProfile.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </CardFooter>
      </Card>
    </section>
  )
}
