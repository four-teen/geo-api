<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Models\BowBarangay;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AccountManagementController extends BaseController
{
    private const DEFAULT_PERMISSIONS = [
        ['code' => 'bow.manage_geo', 'label' => 'Manage Barangay, Purok, Precinct, and Voters'],
        ['code' => 'bow.view_geo', 'label' => 'View Barangay, Purok, Precinct, and Voters'],
    ];

    private const ROLE_LABELS = [
        'administrator' => 'Administrator',
        'staff' => 'Staff',
        'municipal_staff' => 'Municipal Staff',
        'viewer' => 'Viewer',
    ];

    public function options(Request $request): JsonResponse
    {
        $this->ensureDefaultPermissions();
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $permissions = Permission::query()
            ->orderBy('label')
            ->get(['id', 'code', 'label']);

        $barangays = BowBarangay::query()
            ->orderBy('barangay_name')
            ->get(['barangay_id', 'barangay_name', 'status']);

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => collect($this->allowedRolesForActor($actor))
                    ->map(fn (string $role) => [
                        'value' => $role,
                        'label' => $this->roleLabel($role),
                    ])
                    ->values(),
                'permissions' => $permissions,
                'barangays' => $barangays,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $users = $this->visibleUsersQuery($actor)
            ->with([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users->map(fn (User $user) => $this->serializeUser($user))->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $user = User::query()
            ->with([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ])
            ->findOrFail($id);

        $this->ensureActorCanManageTarget($actor, $user);

        return response()->json([
            'success' => true,
            'data' => $this->serializeUser($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $validated = $this->validatePayload($request, true, $actor);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'designation' => $validated['designation'] ?? null,
                'email' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
                'can_delete' => (bool) $validated['can_delete'],
                'barangay_scope' => $validated['barangay_scope'],
                'must_change_password' => true,
            ]);

            $this->syncAssignments($user, $validated);

            return $user->load([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeUser($user),
            'message' => 'Account created successfully.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $this->ensureActorCanManageTarget($actor, $user);
        $validated = $this->validatePayload($request, false, $actor, $user);

        if ((int) $actor->id === (int) $user->id && !$validated['is_active']) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot disable your own account.',
            ], 422);
        }

        $user = DB::transaction(function () use ($user, $validated) {
            $payload = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'designation' => $validated['designation'] ?? null,
                'email' => $validated['username'],
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
                'can_delete' => (bool) $validated['can_delete'],
                'barangay_scope' => $validated['barangay_scope'],
            ];

            if (!empty($validated['password'])) {
                $payload['password'] = Hash::make($validated['password']);
                $payload['must_change_password'] = true;
            }

            $user->update($payload);

            $this->syncAssignments($user, $validated);

            return $user->load([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeUser($user),
            'message' => 'Account updated successfully.',
        ]);
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        if ((int) $actor->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot disable your own account.',
            ], 422);
        }

        $user = User::findOrFail($id);
        $this->ensureActorCanManageTarget($actor, $user);

        $user->forceFill([
            'is_active' => false,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Account disabled successfully.',
            'data' => $this->serializeUser($user->load([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ])),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ((int) $request->user()->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully.',
        ]);
    }

    private function syncAssignments(User $user, array $validated): void
    {
        $permissionIds = Permission::query()
            ->whereIn('code', $validated['permission_codes'])
            ->pluck('id')
            ->all();

        $user->permissions()->sync($permissionIds);

        if ($validated['barangay_scope'] === 'SPECIFIC') {
            $user->barangays()->sync($validated['barangay_ids']);
            return;
        }

        $user->barangays()->sync([]);
    }

    private function validatePayload(Request $request, bool $isCreate, User $actor, ?User $user = null): array
    {
        $this->ensureDefaultPermissions();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'designation' => ['nullable', 'string', 'max:255'],
            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:6'],
            'role' => ['required', Rule::in($this->allowedRolesForActor($actor))],
            'is_active' => ['required', 'boolean'],
            'can_delete' => ['nullable', 'boolean'],
            'barangay_scope' => ['nullable', Rule::in(['ALL', 'SPECIFIC'])],
            'barangay_ids' => ['nullable', 'array'],
            'barangay_ids.*' => ['integer', 'exists:bow_tbl_barangays,barangay_id'],
        ]);

        if ($validated['role'] === 'administrator') {
            $validated['barangay_scope'] = 'ALL';
            $validated['barangay_ids'] = [];
            $validated['can_delete'] = true;
            $validated['permission_codes'] = Permission::query()->pluck('code')->all();

            return $validated;
        }

        $validated['can_delete'] = $actor->isAdministrator()
            ? (bool) ($validated['can_delete'] ?? false)
            : false;
        $validated['barangay_scope'] = $validated['barangay_scope'] ?? 'ALL';
        $validated['permission_codes'] = $this->defaultPermissionCodesForRole($validated['role']);

        if ($validated['barangay_scope'] === 'SPECIFIC') {
            $validated['barangay_ids'] = array_values(array_unique($validated['barangay_ids'] ?? []));

            if (count($validated['barangay_ids']) === 0) {
                throw ValidationException::withMessages([
                    'barangay_ids' => ['Select at least one barangay when barangay scope is SPECIFIC.'],
                ]);
            }
        } else {
            $validated['barangay_ids'] = [];
        }

        return $validated;
    }

    private function visibleUsersQuery(User $actor)
    {
        $query = User::query();

        if (!$actor->isAdministrator()) {
            $query->where('role', '!=', 'administrator');
        }

        return $query;
    }

    private function allowedRolesForActor(User $actor): array
    {
        if ($actor->isAdministrator()) {
            return array_keys(self::ROLE_LABELS);
        }

        return ['staff', 'municipal_staff', 'viewer'];
    }

    private function defaultPermissionCodesForRole(string $role): array
    {
        if ($role === 'staff') {
            return ['bow.manage_geo', 'bow.view_geo'];
        }

        if (in_array($role, ['municipal_staff', 'viewer'], true)) {
            return ['bow.view_geo'];
        }

        return [];
    }

    private function ensureActorCanManageTarget(User $actor, User $target): void
    {
        if ($actor->isAdministrator()) {
            return;
        }

        if ($target->isAdministrator()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403));
        }
    }

    private function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    private function ensureDefaultPermissions(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::DEFAULT_PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                ['label' => $permission['label']]
            );
        }
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username ?: $user->email,
            'designation' => $user->designation,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'can_delete' => $user->isAdministrator() ? true : (bool) $user->can_delete,
            'must_change_password' => (bool) $user->must_change_password,
            'barangay_scope' => $user->barangay_scope,
            'barangays' => $user->barangays->map(fn ($barangay) => [
                'barangay_id' => $barangay->barangay_id,
                'barangay_name' => $barangay->barangay_name,
                'status' => $barangay->status,
            ])->values(),
            'permission_codes' => $user->permissions->pluck('code')->values(),
            'permissions' => $user->permissions->map(fn ($permission) => [
                'id' => $permission->id,
                'code' => $permission->code,
                'label' => $permission->label,
            ])->values(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
