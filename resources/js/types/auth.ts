export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AuthTenant = {
    id: string;
    name: string;
    slug: string;
    role: string;
    timezone?: string;
};

export type Auth = {
    user: User | null;
    is_platform_admin: boolean;
    is_platform_operator: boolean;
    can_manage_integrations: boolean;
    central_permissions: string[];
    tenants: AuthTenant[];
    two_factor_enabled?: boolean;
    encourage_two_factor?: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

export type IntegrationCredentialsFlash = {
    integration_id: string;
    secret: string;
    name: string;
    action: 'created' | 'rotated';
};
