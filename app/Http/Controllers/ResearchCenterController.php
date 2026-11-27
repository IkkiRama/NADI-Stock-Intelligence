<?php

namespace App\Http\Controllers;

use App\Domain\Research\Services\ResearchCenterService;
use App\Models\Instrument;
use App\Models\ResearchNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResearchCenterController extends Controller
{
    public function index(Request $request, ResearchCenterService $research): Response
    {
        $user = $request->user();
        $filters = [
            'template' => (string) $request->query('template', 'ALL'),
            'stance' => (string) $request->query('stance', 'ALL'),
            'conviction' => (string) $request->query('conviction', 'ALL'),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'q' => trim((string) $request->query('q', '')),
            'note' => $request->query('note') ? (int) $request->query('note') : null,
        ];

        $notes = $research->listForUser($user, $filters);
        $instruments = Instrument::query()
            ->with('exchange:id,code')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'exchange_id', 'symbol', 'name', 'currency']);

        return Inertia::render('research/index', [
            'notes' => $notes,
            'filters' => $filters,
            'templates' => ResearchCenterService::TEMPLATES,
            'convictions' => ResearchCenterService::CONVICTIONS,
            'stances' => ResearchCenterService::STANCES,
            'horizons' => ResearchCenterService::HORIZONS,
            'instruments' => $instruments->map(fn (Instrument $i): array => [
                'id' => $i->id,
                'symbol' => $i->symbol,
                'name' => $i->name,
                'exchange_code' => $i->exchange?->code ?? '',
                'currency' => $i->currency,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateNote($request);

        $instrument = ! empty($validated['instrument_id'])
            ? Instrument::query()->find($validated['instrument_id'])
            : null;

        ResearchNote::query()->create([
            'user_id' => $request->user()->id,
            'instrument_id' => $instrument?->id,
            'title' => $validated['title'],
            'template_type' => $validated['template_type'],
            'conviction' => $validated['conviction'],
            'stance' => $validated['stance'],
            'target_price' => $validated['target_price'] ?? null,
            'target_currency' => $validated['target_currency'] ?? $instrument?->currency,
            'horizon' => $validated['horizon'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'thesis_body' => $validated['thesis_body'],
            'catalysts' => $validated['catalysts'] ?? null,
            'risks' => $validated['risks'] ?? null,
            'invalidation_criteria' => $validated['invalidation_criteria'] ?? null,
            'tags' => $this->normalizeTags($validated['tags_text'] ?? null),
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'reviewed_at' => now('UTC')->toDateString(),
            'is_demo' => false,
        ]);

        return back()->with('status', 'research-note-created');
    }

    public function update(Request $request, ResearchNote $researchNote): RedirectResponse
    {
        abort_if($researchNote->user_id !== $request->user()->id, 403);

        $validated = $this->validateNote($request);
        $instrument = ! empty($validated['instrument_id'])
            ? Instrument::query()->find($validated['instrument_id'])
            : null;

        $researchNote->update([
            'instrument_id' => $instrument?->id,
            'title' => $validated['title'],
            'template_type' => $validated['template_type'],
            'conviction' => $validated['conviction'],
            'stance' => $validated['stance'],
            'target_price' => $validated['target_price'] ?? null,
            'target_currency' => $validated['target_currency'] ?? $instrument?->currency,
            'horizon' => $validated['horizon'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'thesis_body' => $validated['thesis_body'],
            'catalysts' => $validated['catalysts'] ?? null,
            'risks' => $validated['risks'] ?? null,
            'invalidation_criteria' => $validated['invalidation_criteria'] ?? null,
            'tags' => $this->normalizeTags($validated['tags_text'] ?? null),
            'is_pinned' => (bool) ($validated['is_pinned'] ?? $researchNote->is_pinned),
            'reviewed_at' => now('UTC')->toDateString(),
            'is_demo' => false,
        ]);

        return back()->with('status', 'research-note-updated');
    }

    public function togglePin(Request $request, ResearchNote $researchNote): RedirectResponse
    {
        abort_if($researchNote->user_id !== $request->user()->id, 403);

        $researchNote->forceFill(['is_pinned' => ! $researchNote->is_pinned])->save();

        return back()->with('status', 'research-note-pinned');
    }

    public function destroy(Request $request, ResearchNote $researchNote): RedirectResponse
    {
        abort_if($researchNote->user_id !== $request->user()->id, 403);

        $researchNote->delete();

        return back()->with('status', 'research-note-deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateNote(Request $request): array
    {
        return $request->validate([
            'instrument_id' => ['nullable', 'integer', 'exists:instruments,id'],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'template_type' => ['required', Rule::in(ResearchCenterService::TEMPLATES)],
            'conviction' => ['required', Rule::in(ResearchCenterService::CONVICTIONS)],
            'stance' => ['required', Rule::in(ResearchCenterService::STANCES)],
            'target_price' => ['nullable', 'numeric', 'min:0.0001'],
            'target_currency' => ['nullable', 'string', 'max:8'],
            'horizon' => ['nullable', Rule::in(ResearchCenterService::HORIZONS)],
            'summary' => ['nullable', 'string', 'max:1000'],
            'thesis_body' => ['required', 'string', 'min:5', 'max:20000'],
            'catalysts' => ['nullable', 'string', 'max:4000'],
            'risks' => ['nullable', 'string', 'max:4000'],
            'invalidation_criteria' => ['nullable', 'string', 'max:4000'],
            'tags_text' => ['nullable', 'string', 'max:300'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $tags = array_values(array_unique(array_filter(
            array_map(fn (string $t): string => mb_substr(trim($t), 0, 32), $parts),
            fn (string $t): bool => $t !== '',
        )));

        return array_slice($tags, 0, 10);
    }
}
