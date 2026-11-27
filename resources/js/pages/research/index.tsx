import { ChangeBadge } from '@/components/nadi/change-badge';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Edit3, Pin, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ResearchNoteItem {
    id: number;
    instrument_id: number | null;
    title: string;
    template_type: string;
    conviction: string;
    stance: string;
    target_price: string | null;
    target_currency: string | null;
    implied_upside_pct: string | null;
    horizon: string | null;
    summary: string | null;
    thesis_body: string;
    catalysts: string | null;
    risks: string | null;
    invalidation_criteria: string | null;
    tags: string[];
    is_pinned: boolean;
    reviewed_at: string | null;
    updated_at: string | null;
    is_demo: boolean;
    instrument: {
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        close: string | null;
        change_pct: string | null;
        overall_score: string | null;
        safety_category: string | null;
        safeguard_triggered: boolean;
    } | null;
}

interface ResearchPageProps {
    notes: ResearchNoteItem[];
    filters: {
        template: string;
        stance: string;
        conviction: string;
        symbol: string;
        q: string;
        note: number | null;
    };
    templates: string[];
    convictions: string[];
    stances: string[];
    horizons: string[];
    instruments: Array<{ id: number; symbol: string; name: string; exchange_code: string; currency: string }>;
}

export default function ResearchCenterPage({
    notes,
    filters,
    templates,
    convictions,
    stances,
    horizons,
    instruments,
}: ResearchPageProps) {
    const { t, locale } = useT();
    const { flash } = usePage<SharedData>().props;

    const [searchQ, setSearchQ] = useState(filters.q);
    const [selectedNote, setSelectedNote] = useState<ResearchNoteItem | null>(() => {
        if (filters.note) {
            return notes.find((n) => n.id === filters.note) ?? notes[0] ?? null;
        }
        return notes[0] ?? null;
    });

    const [editorOpen, setEditorOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [instrumentId, setInstrumentId] = useState<string>('NONE');
    const [title, setTitle] = useState('');
    const [templateType, setTemplateType] = useState('thesis');
    const [conviction, setConviction] = useState('medium');
    const [stance, setStance] = useState('researching');
    const [horizon, setHorizon] = useState('long_term');
    const [targetPrice, setTargetPrice] = useState('');
    const [summary, setSummary] = useState('');
    const [thesisBody, setThesisBody] = useState('');
    const [catalysts, setCatalysts] = useState('');
    const [risks, setRisks] = useState('');
    const [invalidation, setInvalidation] = useState('');
    const [tagsText, setTagsText] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (filters.note) {
            const match = notes.find((n) => n.id === filters.note);
            if (match) {
                setSelectedNote(match);
                return;
            }
        }
        setSelectedNote((prev) => {
            if (prev) {
                const updated = notes.find((n) => n.id === prev.id);
                if (updated) return updated;
            }
            return notes[0] ?? null;
        });
    }, [notes, filters.note]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.research_center'), href: '/research-center' }];

    const navigateFilters = (overrides: Record<string, string>) => {
        const next: Record<string, string> = {
            template: overrides.template ?? filters.template,
            stance: overrides.stance ?? filters.stance,
            conviction: overrides.conviction ?? filters.conviction,
            q: overrides.q ?? searchQ,
        };
        router.get('/research-center', next, { preserveState: true, preserveScroll: true });
    };

    const openCreateModal = () => {
        setEditingId(null);
        setInstrumentId('NONE');
        setTitle('');
        setTemplateType('thesis');
        setConviction('medium');
        setStance('researching');
        setHorizon('long_term');
        setTargetPrice('');
        setSummary('');
        setThesisBody(t('research.template_starter.thesis'));
        setCatalysts('');
        setRisks('');
        setInvalidation('');
        setTagsText('');
        setEditorOpen(true);
    };

    const openEditModal = (note: ResearchNoteItem) => {
        setEditingId(note.id);
        setInstrumentId(note.instrument_id ? String(note.instrument_id) : 'NONE');
        setTitle(note.title);
        setTemplateType(note.template_type);
        setConviction(note.conviction);
        setStance(note.stance);
        setHorizon(note.horizon ?? 'long_term');
        setTargetPrice(note.target_price ? String(Number(note.target_price)) : '');
        setSummary(note.summary ?? '');
        setThesisBody(note.thesis_body);
        setCatalysts(note.catalysts ?? '');
        setRisks(note.risks ?? '');
        setInvalidation(note.invalidation_criteria ?? '');
        setTagsText(note.tags.join(', '));
        setEditorOpen(true);
    };

    const applyTemplateStarter = (tpl: string) => {
        setTemplateType(tpl);
        if (!editingId) {
            setThesisBody(t(`research.template_starter.${tpl}`));
        }
    };

    const submitNote = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        const pickedInst = instrumentId !== 'NONE' ? instruments.find((i) => i.id === Number(instrumentId)) : undefined;
        const payload = {
            instrument_id: pickedInst ? pickedInst.id : null,
            title,
            template_type: templateType,
            conviction,
            stance,
            horizon,
            target_price: targetPrice !== '' ? Number(targetPrice) : null,
            target_currency: pickedInst?.currency ?? null,
            summary: summary || null,
            thesis_body: thesisBody,
            catalysts: catalysts || null,
            risks: risks || null,
            invalidation_criteria: invalidation || null,
            tags_text: tagsText || null,
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => setEditorOpen(false),
            onFinish: () => setSubmitting(false),
        };

        if (editingId) {
            router.put(`/research-center/notes/${editingId}`, payload, options);
        } else {
            router.post('/research-center/notes', payload, options);
        }
    };

    const togglePin = (id: number) => {
        router.patch(`/research-center/notes/${id}/pin`, {}, { preserveScroll: true });
    };

    const deleteNote = (id: number) => {
        router.delete(`/research-center/notes/${id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('research.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-lavender)]/30 text-foreground flex h-9 w-9 items-center justify-center rounded-md">
                            <BookOpen className="h-4 w-4" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">{t('research.title')}</h1>
                            <p className="text-muted-foreground text-xs">{t('research.subtitle')}</p>
                        </div>
                    </div>

                    <Button type="button" size="sm" className="h-8 gap-1 text-xs" onClick={openCreateModal}>
                        <Plus className="h-3.5 w-3.5" />
                        <span>{t('research.new_note')}</span>
                    </Button>
                </div>

                {flash?.status && (
                    <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded-md px-3 py-2 text-xs font-medium">
                        Status: {flash.status}
                    </div>
                )}

                {/* Filter Bar */}
                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.template} onValueChange={(v) => navigateFilters({ template: v })}>
                            <SelectTrigger className="h-8 w-40 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('research.filter.all_templates')}</SelectItem>
                                {templates.map((tpl) => (
                                    <SelectItem key={tpl} value={tpl}>
                                        {t(`research.tpl.${tpl}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.stance} onValueChange={(v) => navigateFilters({ stance: v })}>
                            <SelectTrigger className="h-8 w-36 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('research.filter.all_stances')}</SelectItem>
                                {stances.map((st) => (
                                    <SelectItem key={st} value={st}>
                                        {t(`research.stance.${st}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.conviction} onValueChange={(v) => navigateFilters({ conviction: v })}>
                            <SelectTrigger className="h-8 w-36 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('research.filter.all_convictions')}</SelectItem>
                                {convictions.map((cv) => (
                                    <SelectItem key={cv} value={cv}>
                                        {t(`research.conv.${cv}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            navigateFilters({ q: searchQ });
                        }}
                        className="flex items-center gap-1"
                    >
                        <div className="relative">
                            <Search className="text-muted-foreground absolute top-2 left-2.5 h-3.5 w-3.5" />
                            <Input
                                value={searchQ}
                                onChange={(e) => setSearchQ(e.target.value)}
                                placeholder={t('research.search_placeholder')}
                                className="h-8 w-44 pl-8 text-xs"
                            />
                        </div>
                        <Button type="submit" size="sm" variant="outline" className="h-8 text-xs">
                            {t('app.search')}
                        </Button>
                    </form>
                </div>

                {notes.length === 0 ? (
                    <EmptyState
                        icon={BookOpen}
                        title={t('research.empty.title')}
                        description={t('research.empty.desc')}
                        actionLabel={t('research.new_note')}
                        onAction={openCreateModal}
                    />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-12">
                        {/* Notes List */}
                        <div className="space-y-2 lg:col-span-5">
                            {notes.map((n) => {
                                const isActive = selectedNote?.id === n.id;
                                return (
                                    <button
                                        key={n.id}
                                        type="button"
                                        onClick={() => setSelectedNote(n)}
                                        className={cn(
                                            'bg-card border-border hover:border-foreground/30 w-full rounded-md border p-3 text-left transition-colors',
                                            isActive && 'border-foreground/50 ring-1 ring-foreground/15',
                                        )}
                                    >
                                        <div className="mb-1 flex flex-wrap items-center justify-between gap-1.5 text-[11px]">
                                            <div className="flex items-center gap-1.5">
                                                {n.is_pinned && <Pin className="text-primary h-3 w-3" />}
                                                <span className="bg-muted rounded px-1.5 py-0.5 font-medium">
                                                    {t(`research.tpl.${n.template_type}`)}
                                                </span>
                                                <span className="border-border rounded border px-1.5 py-0.5">
                                                    {t(`research.stance.${n.stance}`)}
                                                </span>
                                                {n.is_demo && <DemoBadge />}
                                            </div>
                                            {n.instrument && (
                                                <span className="font-semibold">
                                                    {n.instrument.symbol} · {n.instrument.exchange_code}
                                                </span>
                                            )}
                                        </div>
                                        <h2 className="text-sm font-semibold leading-snug">{n.title}</h2>
                                        {n.summary && <p className="text-muted-foreground mt-1 line-clamp-2 text-xs">{n.summary}</p>}
                                        <div className="text-muted-foreground mt-2 flex items-center justify-between text-[11px]">
                                            <span>
                                                {t('research.field.conviction')}: <strong>{t(`research.conv.${n.conviction}`)}</strong>
                                            </span>
                                            {n.implied_upside_pct !== null && (
                                                <span className="num">
                                                    {t('research.implied_upside')}: <ChangeBadge changePct={n.implied_upside_pct} />
                                                </span>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Thesis Detail Pane */}
                        <div className="lg:col-span-7">
                            {selectedNote ? (
                                <article className="bg-card border-border sticky top-4 rounded-md border p-5 space-y-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-1.5 text-xs">
                                            <span className="bg-muted rounded px-2 py-0.5 font-medium">
                                                {t(`research.tpl.${selectedNote.template_type}`)}
                                            </span>
                                            <span className="border-border rounded border px-2 py-0.5">
                                                {t(`research.stance.${selectedNote.stance}`)}
                                            </span>
                                            <span className="border-border rounded border px-2 py-0.5">
                                                {t(`research.conv.${selectedNote.conviction}`)}
                                            </span>
                                            {selectedNote.is_demo && <DemoBadge />}
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 px-2 text-xs"
                                                onClick={() => togglePin(selectedNote.id)}
                                            >
                                                <Pin className={cn('h-3.5 w-3.5', selectedNote.is_pinned && 'text-primary')} />
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-7 gap-1 px-2 text-xs"
                                                onClick={() => openEditModal(selectedNote)}
                                            >
                                                <Edit3 className="h-3.5 w-3.5" />
                                                <span>{t('research.edit_note')}</span>
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive h-7 px-2 text-xs"
                                                onClick={() => deleteNote(selectedNote.id)}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>

                                    <div>
                                        <h2 className="text-base font-semibold">{selectedNote.title}</h2>
                                        {selectedNote.reviewed_at && (
                                            <p className="text-muted-foreground num mt-0.5 text-[11px]">
                                                {t('research.reviewed_at', { date: formatDate(selectedNote.reviewed_at, locale) })}
                                            </p>
                                        )}
                                    </div>

                                    {selectedNote.instrument && (
                                        <div className="border-border bg-muted/25 flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-xs">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/stocks/${selectedNote.instrument.exchange_code}/${selectedNote.instrument.symbol}`}
                                                    className="font-semibold hover:underline"
                                                >
                                                    {selectedNote.instrument.symbol} · {selectedNote.instrument.exchange_code}
                                                </Link>
                                                <span className="text-muted-foreground">{selectedNote.instrument.name}</span>
                                                {selectedNote.instrument.safety_category && (
                                                    <SafetyBadge
                                                        category={selectedNote.instrument.safety_category}
                                                        safeguard={selectedNote.instrument.safeguard_triggered}
                                                    />
                                                )}
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {selectedNote.instrument.close && (
                                                    <span className="num">
                                                        {t('research.current_px')}:{' '}
                                                        <strong>
                                                            {formatPrice(selectedNote.instrument.close, selectedNote.instrument.currency, locale)}
                                                        </strong>
                                                    </span>
                                                )}
                                                {selectedNote.target_price && (
                                                    <span className="num">
                                                        {t('research.target_px')}:{' '}
                                                        <Money
                                                            value={selectedNote.target_price}
                                                            currency={selectedNote.target_currency ?? selectedNote.instrument.currency}
                                                        />
                                                    </span>
                                                )}
                                                {selectedNote.implied_upside_pct !== null && (
                                                    <ChangeBadge changePct={selectedNote.implied_upside_pct} pill />
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    <div className="whitespace-pre-line text-xs leading-relaxed">{selectedNote.thesis_body}</div>

                                    {(selectedNote.catalysts || selectedNote.risks || selectedNote.invalidation_criteria) && (
                                        <div className="grid gap-3 border-t pt-3 md:grid-cols-3">
                                            <div className="border-border rounded border p-2.5 text-xs">
                                                <div className="text-[var(--nadi-gain-ink)] mb-1 font-semibold">{t('research.field.catalysts')}</div>
                                                <p className="text-muted-foreground leading-relaxed">{selectedNote.catalysts || '—'}</p>
                                            </div>
                                            <div className="border-border rounded border p-2.5 text-xs">
                                                <div className="text-[var(--nadi-loss-ink)] mb-1 font-semibold">{t('research.field.risks')}</div>
                                                <p className="text-muted-foreground leading-relaxed">{selectedNote.risks || '—'}</p>
                                            </div>
                                            <div className="border-border rounded border p-2.5 text-xs">
                                                <div className="mb-1 font-semibold">{t('research.field.invalidation')}</div>
                                                <p className="text-muted-foreground leading-relaxed">
                                                    {selectedNote.invalidation_criteria || '—'}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {selectedNote.tags.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5 pt-1">
                                            {selectedNote.tags.map((tag) => (
                                                <span key={tag} className="bg-muted text-muted-foreground rounded px-2 py-0.5 text-[11px]">
                                                    #{tag}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </article>
                            ) : (
                                <EmptyState icon={BookOpen} title={t('research.empty.title')} />
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Create / Edit Modal */}
            <Dialog open={editorOpen} onOpenChange={setEditorOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editingId ? t('research.edit_note') : t('research.new_note')}</DialogTitle>
                        <DialogDescription>{t('research.editor_desc')}</DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitNote} className="space-y-3 text-xs">
                        <div className="flex flex-wrap items-center gap-1">
                            {templates.map((tpl) => (
                                <Button
                                    key={tpl}
                                    type="button"
                                    size="sm"
                                    variant={templateType === tpl ? 'secondary' : 'outline'}
                                    className="h-7 text-[11px]"
                                    onClick={() => applyTemplateStarter(tpl)}
                                >
                                    {t(`research.tpl.${tpl}`)}
                                </Button>
                            ))}
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <Label className="text-xs">{t('research.field.title')}</Label>
                                <Input value={title} onChange={(e) => setTitle(e.target.value)} required className="mt-1 h-8 text-xs" />
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.field.ticker')}</Label>
                                <Select value={instrumentId} onValueChange={setInstrumentId}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="NONE">{t('research.no_ticker')}</SelectItem>
                                        {instruments.map((inst) => (
                                            <SelectItem key={inst.id} value={String(inst.id)}>
                                                {inst.symbol} · {inst.exchange_code} ({inst.currency})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-4">
                            <div>
                                <Label className="text-xs">{t('research.field.stance')}</Label>
                                <Select value={stance} onValueChange={setStance}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {stances.map((st) => (
                                            <SelectItem key={st} value={st}>
                                                {t(`research.stance.${st}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.field.conviction')}</Label>
                                <Select value={conviction} onValueChange={setConviction}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {convictions.map((cv) => (
                                            <SelectItem key={cv} value={cv}>
                                                {t(`research.conv.${cv}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.field.horizon')}</Label>
                                <Select value={horizon} onValueChange={setHorizon}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {horizons.map((hz) => (
                                            <SelectItem key={hz} value={hz}>
                                                {t(`signals.horizon.${hz}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.target_px')}</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={targetPrice}
                                    onChange={(e) => setTargetPrice(e.target.value)}
                                    className="mt-1 h-8 text-xs"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs">{t('research.field.summary')}</Label>
                            <Input value={summary} onChange={(e) => setSummary(e.target.value)} className="mt-1 h-8 text-xs" />
                        </div>

                        <div>
                            <Label className="text-xs">{t('research.field.thesis_body')}</Label>
                            <textarea
                                value={thesisBody}
                                onChange={(e) => setThesisBody(e.target.value)}
                                required
                                rows={5}
                                className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                            />
                        </div>

                        <div className="grid gap-2 sm:grid-cols-3">
                            <div>
                                <Label className="text-xs">{t('research.field.catalysts')}</Label>
                                <textarea
                                    value={catalysts}
                                    onChange={(e) => setCatalysts(e.target.value)}
                                    rows={2}
                                    className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                                />
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.field.risks')}</Label>
                                <textarea
                                    value={risks}
                                    onChange={(e) => setRisks(e.target.value)}
                                    rows={2}
                                    className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                                />
                            </div>
                            <div>
                                <Label className="text-xs">{t('research.field.invalidation')}</Label>
                                <textarea
                                    value={invalidation}
                                    onChange={(e) => setInvalidation(e.target.value)}
                                    rows={2}
                                    className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs">{t('research.field.tags')}</Label>
                            <Input
                                value={tagsText}
                                onChange={(e) => setTagsText(e.target.value)}
                                placeholder="core, idx, dividend"
                                className="mt-1 h-8 text-xs"
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" size="sm" onClick={() => setEditorOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit" size="sm" disabled={submitting}>
                                {t('research.save_note')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
