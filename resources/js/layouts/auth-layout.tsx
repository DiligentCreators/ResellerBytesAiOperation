import { usePage } from '@inertiajs/react';
import AuthCardLayout from '@/layouts/auth/auth-card-layout';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';

const SPLIT_PAGES = new Set(['auth/login', 'auth/register']);

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const page = usePage();
    const component = page.component;
    const Layout = SPLIT_PAGES.has(component)
        ? AuthSplitLayout
        : component === 'auth/two-factor-challenge'
          ? AuthCardLayout
          : AuthSimpleLayout;

    return (
        <Layout title={title} description={description}>
            {children}
        </Layout>
    );
}
