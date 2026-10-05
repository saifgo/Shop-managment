import { zodResolver } from '@hookform/resolvers/zod';
import { AlertCircleIcon } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Field,
  FieldError,
  FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useAuth } from '@/features/auth/hooks/useAuth';
import { loginSchema, type LoginFormValues } from '@/lib/forms/schemas';
import { ApiError } from '@/lib/api/client';

interface LoginPageProps {
  title: string;
  subtitle: string;
  homePath: string;
  alternateLoginPath?: string;
  alternateLabel?: string;
}

export function LoginPage({
  title,
  subtitle,
  homePath,
  alternateLoginPath,
  alternateLabel,
}: LoginPageProps) {
  const { login, isAuthenticated, isLoading } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  });

  const redirectTo = (location.state as { from?: string } | null)?.from ?? homePath;

  if (!isLoading && isAuthenticated) {
    return <Navigate to={redirectTo} replace />;
  }

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);

    try {
      await login(values);
      navigate(redirectTo, { replace: true });
    } catch (error) {
      if (error instanceof ApiError) {
        setFormError(error.message);
      } else {
        setFormError('Unable to sign in. Please try again.');
      }
    }
  });

  return (
    <div className="grid min-h-[100dvh] lg:grid-cols-[1fr_1.1fr]">
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-primary p-10 text-primary-foreground lg:flex">
        <div className="flex items-center gap-2.5">
          <span className="grid size-9 place-items-center rounded-lg bg-primary-foreground/12 text-base font-semibold ring-1 ring-primary-foreground/20">
            T
          </span>
          <span className="text-lg font-semibold tracking-tight">Tittawin</span>
        </div>
        <div className="flex max-w-md flex-col gap-4">
          <h2 className="font-heading text-3xl font-semibold leading-tight tracking-tight">
            Orders, stock and production in one calm workspace.
          </h2>
          <p className="text-sm/6 text-primary-foreground/70">
            Track every sale from quote to delivery, keep inventory accurate and
            see what your workshop is making next.
          </p>
        </div>
        <p className="text-xs text-primary-foreground/50">
          © {new Date().getFullYear()} Tittawin
        </p>
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -right-24 -bottom-24 size-96 rounded-full bg-primary-foreground/6 blur-2xl"
        />
      </aside>

      <main className="flex items-center justify-center bg-muted/40 p-6">
        <div className="flex w-full max-w-sm flex-col gap-6">
          <div className="flex items-center gap-2 lg:hidden">
            <span className="grid size-8 place-items-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground">
              T
            </span>
            <span className="font-semibold tracking-tight">Tittawin</span>
          </div>
          <Card>
            <CardHeader>
              <CardTitle className="text-xl">{title}</CardTitle>
              <CardDescription>{subtitle}</CardDescription>
            </CardHeader>
            <CardContent>
              <form className="flex flex-col gap-5" onSubmit={onSubmit} noValidate>
                <div className="flex flex-col gap-4">
                  <Field data-invalid={errors.email ? true : undefined}>
                    <FieldLabel htmlFor="email">Email</FieldLabel>
                    <Input
                      id="email"
                      type="email"
                      autoComplete="email"
                      autoFocus
                      placeholder="you@company.com"
                      aria-invalid={!!errors.email}
                      {...register('email')}
                    />
                    <FieldError>{errors.email?.message}</FieldError>
                  </Field>

                  <Field data-invalid={errors.password ? true : undefined}>
                    <FieldLabel htmlFor="password">Password</FieldLabel>
                    <Input
                      id="password"
                      type="password"
                      autoComplete="current-password"
                      aria-invalid={!!errors.password}
                      {...register('password')}
                    />
                    <FieldError>{errors.password?.message}</FieldError>
                  </Field>
                </div>

                {formError ? (
                  <Alert variant="error">
                    <AlertCircleIcon />
                    <AlertTitle>{formError}</AlertTitle>
                  </Alert>
                ) : null}

                <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? (
                    <>
                      <Spinner />
                      Signing in…
                    </>
                  ) : (
                    'Sign in'
                  )}
                </Button>
              </form>
            </CardContent>
            {alternateLoginPath && alternateLabel ? (
              <CardFooter className="justify-center">
                <Link
                  to={alternateLoginPath}
                  className={buttonVariants({ variant: 'link' })}
                >
                  {alternateLabel}
                </Link>
              </CardFooter>
            ) : null}
          </Card>
        </div>
      </main>
    </div>
  );
}
