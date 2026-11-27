import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    can: {
        accessAdmin: boolean;
    };
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    phase?: number;
    status?: 'available' | 'planned';
}

export interface UserSettings {
    locale: 'id' | 'en';
    appearance: 'light' | 'dark' | 'system';
    density: 'compact' | 'comfortable';
    analysis_mode: 'simple' | 'pro';
    default_currency: string;
    default_market: string;
    default_timeframe: string;
    timezone: string;
    dashboard_layout: DashboardWidgetConfig[] | null;
}

export type DashboardWidgetConfig = {
    id: string;
    visible: boolean;
};

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    appVersion: string;
    demoMode: boolean;
    locale: 'id' | 'en';
    translations: Record<string, string>;
    auth: Auth;
    settings: UserSettings;
    featureFlags: Record<string, boolean>;
    flash: {
        status?: string | null;
        error?: string | null;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_active?: boolean;
    last_login_at?: string | null;
    roles?: string[];
    created_at?: string;
    updated_at?: string;
    [key: string]: unknown;
}

export interface MarketClockStatus {
    state: 'open' | 'break' | 'closed' | 'weekend' | 'holiday';
    local_time: string;
    timezone: string;
    next_open_at: string | null;
    holiday_name: string | null;
    holidays_loaded: boolean;
}

export interface ExchangeClockItem {
    code: string;
    name: string;
    country_code: string;
    currency: string;
    status: MarketClockStatus;
}

export interface IndexCardItem {
    id: number;
    code: string;
    name: string;
    country_code: string;
    currency: string;
    index_type: string;
    exchange_code: string | null;
    close: string | null;
    prev_close: string | null;
    change: string | null;
    change_pct: string | null;
    as_of: string | null;
    is_demo: boolean;
    source: string;
    sparkline: string[];
    return_1m?: string | null;
    return_ytd?: string | null;
    return_1y?: string | null;
}

export interface OhlcvBar {
    date: string;
    open: string;
    high: string;
    low: string;
    close: string;
    volume: number;
    value_traded?: string | null;
}
