import type { Auth, IntegrationCredentialsFlash } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            security: {
                twoFactorRequiredByPlatform: boolean;
            };
            flash: {
                integration_credentials?: IntegrationCredentialsFlash | null;
                status?: string | null;
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
