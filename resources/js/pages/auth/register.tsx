import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type CountryOption = {
    code: string;
    name: string;
};

type Props = {
    countries: CountryOption[];
    passwordRules: string;
};

export default function Register({ countries, passwordRules }: Props) {
    return (
        <>
            <Head title="Create account" />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {/* Honeypot — leave empty */}
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute -left-[9999px] h-0 w-0 opacity-0"
                        >
                            <Label htmlFor="website">Website</Label>
                            <Input
                                id="website"
                                name="website"
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                            />
                        </div>

                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Full name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    placeholder="Ada Lovelace"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Work email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="you@company.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="workspace_name">
                                    Company / workspace name
                                </Label>
                                <Input
                                    id="workspace_name"
                                    name="workspace_name"
                                    required
                                    autoComplete="organization"
                                    placeholder="Acme Hosting"
                                />
                                <InputError message={errors.workspace_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="country">Country</Label>
                                <select
                                    id="country"
                                    name="country"
                                    required
                                    defaultValue=""
                                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <option value="" disabled>
                                        Select your country
                                    </option>
                                    {countries.map((country) => (
                                        <option
                                            key={country.code}
                                            value={country.code}
                                        >
                                            {country.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.country} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                    placeholder="At least 12 characters"
                                    passwordrules={passwordRules}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Use at least 12 characters with mixed case,
                                    a number, and a symbol.
                                </p>
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="terms"
                                    name="terms"
                                    value="1"
                                    className="mt-0.5"
                                />
                                <Label
                                    htmlFor="terms"
                                    className="text-sm leading-5 font-normal"
                                >
                                    I agree to the{' '}
                                    <TextLink href="/terms" tabIndex={-1}>
                                        Terms of Service
                                    </TextLink>{' '}
                                    and{' '}
                                    <TextLink href="/privacy" tabIndex={-1}>
                                        Privacy Policy
                                    </TextLink>
                                    .
                                </Label>
                            </div>
                            <InputError message={errors.terms} />

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                disabled={processing}
                                data-test="register-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <p className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()}>Sign in</TextLink>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create your workspace',
    description:
        'Sign up to connect WHMCS, surface operational risks, and keep fixes human-approved.',
};
