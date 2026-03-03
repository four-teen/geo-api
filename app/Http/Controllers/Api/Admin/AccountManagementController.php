<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Models\BowBarangay;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountManagementController extends BaseController
{
    private const DEFAULT_PERMISSIONS = [
        ['code' => 'bow.manage_geo', 'label' => 'Manage Barangay, Purok, and Precinct'],
        ['code' => 'bow.view_geo', 'label' => 'View Barangay, Purok, and Precinct'],
    ];

    public function options(): JsonResponse
    {
        $this->ensureDefaultPermissions();

        $permissions = Permission::query()
            ->orderBy('label')
            ->get(['id', 'code', 'label']);

        $barangays = BowBarangay::query()
            ->orderBy('barangay_name')
            ->get(['barangay_id', 'barangay_name', 'status']);

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $permissions,
                'barangays' => $barangays,
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $users = User::query()
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

    public function show(int $id): JsonResponse
    {
        $user = User::query()
            ->with([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->serializeUser($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'designation' => $validated['designation'] ?? null,
                'email' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
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

        $validated = $this->validatePayload($request, false, $user);

        $user = DB::transaction(function () use ($user, $validated) {
            $payload = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'designation' => $validated['designation'] ?? null,
                'email' => $validated['username'],
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
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

    private function validatePayload(Request $request, bool $isCreate, ?User $user = null): array
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
            'role' => ['required', Rule::in(['administrator', 'staff'])],
            'is_active' => ['required', 'boolean'],
            'barangay_scope' => ['nullable', Rule::in(['ALL', 'SPECIFIC'])],
            'barangay_ids' => ['nullable', 'array'],
            'barangay_ids.*' => ['integer', 'exists:bow_tbl_barangays,barangay_id'],
            'permission_codes' => ['nullable', 'array'],
            'permission_codes.*' => ['string', 'exists:permissions,code'],
        ]);

        if ($validated['role'] === 'administrator') {
            $validated['barangay_scope'] = 'ALL';
            $validated['barangay_ids'] = [];
            $validated['permission_codes'] = Permission::query()->pluck('code')->all();

            return $validated;
        }

        $validated['barangay_scope'] = $validated['barangay_scope'] ?? 'ALL';
        $validated['permission_codes'] = array_values(array_unique($validated['permission_codes'] ?? []));

        if (count($validated['permission_codes']) === 0) {
            throw ValidationException::withMessages([
                'permission_codes' => ['Select at least one transaction permission for staff role.'],
            ]);
        }

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
