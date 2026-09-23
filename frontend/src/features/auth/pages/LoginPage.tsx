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
  FieldGroup,
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
    <div className="flex min-h-[100dvh] items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <p className="text-muted-foreground">Tittawin</p>
          <CardTitle>{title}</CardTitle>
          <CardDescription>{subtitle}</CardDescription>
        </CardHeader>
        <CardContent>
          <form className="flex flex-col gap-6" onSubmit={onSubmit} noValidate>
            <FieldGroup>
              <Field data-invalid={errors.email ? true : undefined}>
                <FieldLabel htmlFor="email">Email</FieldLabel>
                <Input
                  id="email"
                  type="email"
                  autoComplete="email"
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
            </FieldGroup>

            {formError ? (
              <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertTitle>{formError}</AlertTitle>
              </Alert>
            ) : null}

            <Button type="submit" className="w-full" disabled={isSubmitting}>
              {isSubmitting ? (
                <>
                  <Spinner data-icon="inline-start" />
                  Signing in…
                </>
              ) : (
                'Sign in'
              )}
            </Button>
          </form>
        </CardContent>
        {alternateLoginPath && alternateLabel ? (
          <CardFooter>
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
  );
}
