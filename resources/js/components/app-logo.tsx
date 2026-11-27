import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold tracking-tight">NADI</span>
                <span className="text-muted-foreground truncate text-[10px] font-medium tracking-wider uppercase">Stock Intel</span>
            </div>
        </>
    );
}
