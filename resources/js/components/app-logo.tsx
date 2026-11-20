import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo({
    showWordmark = true,
}: {
    showWordmark?: boolean;
}) {
    return (
        <>
            <AppLogoIcon className="size-7 shrink-0 text-brand" />
            {showWordmark && (
                <div className="ml-1 grid flex-1 text-left text-sm">
                    <span className="truncate leading-tight font-semibold tracking-tight">
                        AI Operations
                    </span>
                    <span className="truncate text-[11px] leading-tight text-muted-foreground">
                        ResellerBytes
                    </span>
                </div>
            )}
        </>
    );
}
