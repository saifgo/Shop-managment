import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PlaceholderPage } from '@/features/shared/pages/PlaceholderPage'

describe('PlaceholderPage', () => {
  it('renders title and description', () => {
    render(
      <PlaceholderPage title="Test Module" description="Coming soon." />,
    )

    expect(screen.getByRole('heading', { name: 'Test Module' })).toBeInTheDocument()
    expect(screen.getByText('Coming soon.')).toBeInTheDocument()
    expect(screen.getByText(/future phase/i)).toBeInTheDocument()
  })
})
