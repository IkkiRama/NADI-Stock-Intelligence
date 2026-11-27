<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('roles:id,slug,name')
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(function ($sub) use ($like): void {
                    $sub->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => (bool) $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'roles' => $u->roles->pluck('slug')->values()->all(),
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'roles' => Role::query()->orderBy('id')->get(['id', 'slug', 'name']),
            'filters' => ['q' => $q],
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        if ($user->id === $request->user()->id) {
            if (! $validated['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Administrator tidak dapat menonaktifkan akunnya sendiri.',
                ]);
            }

            if (! in_array('admin', $validated['roles'], true)) {
                throw ValidationException::withMessages([
                    'roles' => 'Administrator tidak dapat menghapus peran admin dari akunnya sendiri.',
                ]);
            }
        }

        $oldValues = [
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles()->pluck('slug')->values()->all(),
        ];

        $roleIds = Role::query()->whereIn('slug', $validated['roles'])->pluck('id')->all();

        $user->forceFill(['is_active' => (bool) $validated['is_active']])->save();
        $user->roles()->sync($roleIds);

        $newValues = [
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles()->pluck('slug')->values()->all(),
        ];

        $audit->log('user.updated', $user, $oldValues, $newValues, $request);

        return back()->with('status', 'user-updated');
    }
}
