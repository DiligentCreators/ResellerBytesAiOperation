import type { Auth, IntegrationCredentialsFlash } from '@/types/auth';
import type { SharedNavigation } from '@/types/navigation';

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
            navigation: SharedNavigation;
            [key: string]: unknown;
        };
    }
}
