import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="bg-card border-border w-full max-w-sm rounded-md border p-6 shadow-xs">
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-3">
                        <Link href={route('home')} className="flex items-center gap-2 font-medium">
                            <div className="bg-primary text-primary-foreground flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-5" />
                            </div>
                            <span className="text-base font-semibold tracking-tight">NADI</span>
                        </Link>

                        <div className="space-y-1 text-center">
                            <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
                            <p className="text-muted-foreground text-center text-xs leading-relaxed">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
