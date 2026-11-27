import {
    Activity,
    BarChart3,
    Bell,
    BookOpen,
    Briefcase,
    Calculator,
    Compass,
    FileText,
    Filter,
    FlaskConical,
    Globe2,
    Grid3X3,
    LayoutDashboard,
    type LucideIcon,
    Newspaper,
    PieChart,
    Scale,
    ShieldAlert,
    Sparkles,
    Target,
    TrendingUp,
} from 'lucide-react';

export interface NadiNavEntry {
    key: string;
    href: string;
    icon: LucideIcon;
    status: 'available' | 'planned';
    phase: number;
}

export interface NadiNavGroup {
    groupKey: string;
    items: NadiNavEntry[];
}

export const nadiNavigationGroups: NadiNavGroup[] = [
    {
        groupKey: 'nav.group.overview',
        items: [
            { key: 'nav.overview', href: '/dashboard', icon: LayoutDashboard, status: 'available', phase: 1 },
        ],
    },
    {
        groupKey: 'nav.group.markets',
        items: [
            { key: 'nav.global_markets', href: '/markets', icon: Globe2, status: 'available', phase: 1 },
            { key: 'nav.heatmap', href: '/heatmap', icon: Grid3X3, status: 'available', phase: 2 },
            { key: 'nav.stock_explorer', href: '/stocks', icon: Compass, status: 'available', phase: 1 },
            { key: 'nav.screener', href: '/screener', icon: Filter, status: 'available', phase: 2 },
        ],
    },
    {
        groupKey: 'nav.group.intelligence',
        items: [
            { key: 'nav.opportunities', href: '/opportunities', icon: Sparkles, status: 'available', phase: 3 },
            { key: 'nav.stock_safety', href: '/stock-safety', icon: ShieldAlert, status: 'available', phase: 3 },
            { key: 'nav.entry_exit', href: '/entry-exit', icon: Target, status: 'available', phase: 3 },
            { key: 'nav.strategy_lab', href: '/segera/strategy-lab', icon: FlaskConical, status: 'planned', phase: 12 },
        ],
    },
    {
        groupKey: 'nav.group.portfolio',
        items: [
            { key: 'nav.portfolio', href: '/portfolio', icon: Briefcase, status: 'available', phase: 4 },
            { key: 'nav.portfolio_analytics', href: '/segera/portfolio-analytics', icon: BarChart3, status: 'planned', phase: 6 },
            { key: 'nav.dividends', href: '/segera/dividends', icon: TrendingUp, status: 'planned', phase: 8 },
            { key: 'nav.risk_rebalancing', href: '/segera/risk-rebalancing', icon: Scale, status: 'planned', phase: 7 },
            { key: 'nav.reports', href: '/segera/reports', icon: FileText, status: 'planned', phase: 9 },
        ],
    },
    {
        groupKey: 'nav.group.research',
        items: [
            { key: 'nav.newsroom', href: '/segera/newsroom', icon: Newspaper, status: 'planned', phase: 8 },
            { key: 'nav.broker_intelligence', href: '/segera/broker-intelligence', icon: Activity, status: 'planned', phase: 10 },
            { key: 'nav.research_center', href: '/segera/research-center', icon: BookOpen, status: 'planned', phase: 8 },
        ],
    },
    {
        groupKey: 'nav.group.tools',
        items: [
            { key: 'nav.alerts_calendar', href: '/alerts-calendar', icon: Bell, status: 'available', phase: 3 },
            { key: 'nav.calculators', href: '/calculators', icon: Calculator, status: 'available', phase: 4 },
        ],
    },
];

export const widgetIconMap: Record<string, LucideIcon> = {
    market_indices: Globe2,
    market_clocks: Activity,
    breadth_movers: BarChart3,
    sector_performance: PieChart,
    watchlist_movements: Compass,
    portfolio_summary: Briefcase,
    stock_safety_warnings: ShieldAlert,
    trading_signals: Target,
    curated_news: Newspaper,
    upcoming_events: Bell,
};
