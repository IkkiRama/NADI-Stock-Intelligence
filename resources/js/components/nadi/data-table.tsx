import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface DataTableColumn<T> {
    key: string;
    header: ReactNode;
    align?: 'left' | 'right' | 'center';
    sticky?: boolean;
    className?: string;
    cell: (row: T) => ReactNode;
}

interface DataTableProps<T> {
    rows: T[];
    columns: DataTableColumn<T>[];
    rowKey: (row: T) => string | number;
    onRowClick?: (row: T) => void;
    emptyState?: ReactNode;
    className?: string;
}

export function DataTable<T>({ rows, columns, rowKey, onRowClick, emptyState, className }: DataTableProps<T>) {
    if (rows.length === 0 && emptyState) {
        return <>{emptyState}</>;
    }

    return (
        <div className={cn('border-border bg-card overflow-x-auto rounded-md border', className)}>
            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr className="border-border bg-muted/50 border-b">
                        {columns.map((col, idx) => (
                            <th
                                key={col.key}
                                className={cn(
                                    'text-muted-foreground px-3 py-2 text-xs font-semibold whitespace-nowrap',
                                    col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                                    (col.sticky || idx === 0) && 'bg-muted/50 sticky left-0 z-10',
                                    col.className,
                                )}
                            >
                                {col.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={rowKey(row)}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            className={cn(
                                'border-border/70 border-b last:border-b-0',
                                onRowClick && 'hover:bg-muted/40 cursor-pointer transition-colors',
                            )}
                        >
                            {columns.map((col, idx) => (
                                <td
                                    key={col.key}
                                    className={cn(
                                        'px-3 py-[var(--nadi-cell-py)] whitespace-nowrap',
                                        col.align === 'right' ? 'num text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                                        (col.sticky || idx === 0) && 'bg-card sticky left-0 z-10',
                                        col.className,
                                    )}
                                >
                                    {col.cell(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
