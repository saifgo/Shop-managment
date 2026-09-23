import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { ProtectedRoute } from '@/features/auth/components/ProtectedRoute';
import { AuthProvider } from '@/features/auth/context/AuthContext';

vi.mock('@/lib/api/auth', () => ({
  authApi: {
    login: vi.fn(),
    refresh: vi.fn(),
    logout: vi.fn(),
    me: vi.fn().mockRejectedValue(new Error('unauthenticated')),
  },
}));

function renderProtectedRoute(initialPath = '/admin') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<div>Login page</div>} />
          <Route
            path="/admin"
            element={
              <ProtectedRoute loginPath="/admin/login" adminOnly>
                <div>Protected admin content</div>
              </ProtectedRoute>
            }
          />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('ProtectedRoute', () => {
  it('redirects unauthenticated users to login', async () => {
    renderProtectedRoute('/admin');

    expect(await screen.findByText('Login page')).toBeInTheDocument();
  });
});
