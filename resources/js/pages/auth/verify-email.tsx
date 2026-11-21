import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verify your email" />

            <div className="space-y-6 text-center">
                {status === 'verification-link-sent' && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                        A new verification link is on its way. Check your inbox
                        (and spam folder) for the message we just sent.
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    We sent a verification link to the email address you used
                    when creating your workspace. Open that link to unlock
                    onboarding and the operations dashboard. Locally, mail is
                    written to the application log.
                </p>

                <Form {...send.form()} className="space-y-4">
                    {({ processing }) => (
                        <>
                            <Button
                                disabled={processing}
                                variant="secondary"
                                className="w-full"
                            >
                                {processing && <Spinner />}
                                Resend verification email
                            </Button>

                            <TextLink
                                href={logout()}
                                className="mx-auto block text-sm"
                            >
                                Log out
                            </TextLink>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email',
    description:
        'Confirm your email address before accessing your workspace. Resends are rate-limited.',
};
