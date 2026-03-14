<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Helpers\BaseController as BaseController;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator as FacadesValidator;

class AdminController extends BaseController
{
    //

    public function register(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            'name'=>'required',
            'username'=>'required|unique:users,username',
            'password'=>'required',
            'role' => 'nullable|in:administrator,staff,municipal_staff,viewer',
            'designation' => 'nullable|string|max:255',
            'can_delete' => 'nullable|boolean',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $input['username'] = $input['username'] ?? null;
        $input['email'] = $input['username'];
        $input['designation'] = $input['designation'] ?? null;
        $input['must_change_password'] = true;
        $input['role'] = $input['role'] ?? 'staff';
        $input['is_active'] = $input['is_active'] ?? true;
        $input['can_delete'] = ($input['role'] ?? 'staff') === 'administrator'
            ? true
            : (bool) ($input['can_delete'] ?? false);
        $input['barangay_scope'] = $input['barangay_scope'] ?? 'ALL';
        $user = User::create($input);
        $success['token'] = $this->normalizeAccessToken($user->createToken('MyApp')->plainTextToken);
        $success['name'] = $user->name;
        $success['id'] = $user->id;
        $success['role'] = $user->role;
        $success['is_active'] = (bool) $user->is_active;
        $success['can_delete'] = $user->isAdministrator() ? true : (bool) $user->can_delete;
        $success['barangay_scope'] = $user->barangay_scope;
        $success['permission_codes'] = [];
        $success['barangay_ids'] = [];

        return $this->sendResponse($success, 'User register successfully.');
    }

    public function login(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            'username' => 'nullable|required_without:email|string',
            'email' => 'nullable|required_without:username|string',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $loginIdentifier = $request->username ?: $request->email;

        $credentials = [
            'username' => $loginIdentifier,
            'password' => $request->password,
        ];

        if (!Auth::guard('web')->attempt($credentials)) {
            // Backward compatibility for rows created before username field.
            $fallbackCredentials = [
                'email' => $loginIdentifier,
                'password' => $request->password,
            ];

            if (!Auth::guard('web')->attempt($fallbackCredentials)) {
                return $this->sendError('Unauthorised.', ['error'=>'Unauthorised']);
            }
        }

        if(Auth::check()){
            /** @var \App\Models\User $user */
            $user = Auth::user();

            if (!$user->is_active) {
                return $this->sendError('Account is inactive.', ['error' => 'Account is inactive.'], 403);
            }

            $user->load([
                'permissions:id,code,label',
                'barangays:barangay_id,barangay_name,status',
            ]);

            $permissionCodes = $user->role === 'administrator'
                ? Permission::query()->orderBy('code')->pluck('code')->values()
                : $user->permissions->pluck('code')->values();

            $success['token'] = $this->normalizeAccessToken($user->createToken('MyApp')->plainTextToken);
            $success['name'] =  $user->name;
            $success['id'] =  $user->id;
            $success['username'] = $user->username ?: $user->email;
            $success['designation'] = $user->designation;
            $success['role'] = $user->role;
            $success['is_active'] = (bool) $user->is_active;
            $success['can_delete'] = $user->isAdministrator() ? true : (bool) $user->can_delete;
            $success['must_change_password'] = (bool) $user->must_change_password;
            $success['barangay_scope'] = $user->barangay_scope;
            $success['permission_codes'] = $permissionCodes;
            $success['barangay_ids'] = $user->barangays->pluck('barangay_id')->values();
            $success['barangays'] = $user->barangays->map(fn ($barangay) => [
                'barangay_id' => $barangay->barangay_id,
                'barangay_name' => $barangay->barangay_name,
                'status' => $barangay->status,
            ])->values();

            return $this->sendResponse($success, 'User login successfully.');
        }
        return $this->sendError('Unauthorised.', ['error'=>'Unauthorised']);
    }

    public function changePassword(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed', 'different:current_password'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorised.', ['error'=>'Unauthorised'], 401);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->sendError(
                'Current password is incorrect.',
                ['current_password' => ['Current password is incorrect.']],
                422
            );
        }

        $user->forceFill([
            'password' => bcrypt($request->password),
            'must_change_password' => false,
        ])->save();

        return $this->sendResponse([
            'must_change_password' => false,
        ], 'Password changed successfully.');
    }

    public function logout(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorised.', ['error' => 'Unauthorised'], 401);
        }

        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        return $this->sendResponse([], 'User logout successfully.');
    }

    /**
     * Normalize Sanctum plainTextToken for environments where token IDs
     * may be unreliable (e.g., returning "0|...").
     */
    private function normalizeAccessToken(string $token): string
    {
        return str_contains($token, '|')
            ? explode('|', $token, 2)[1]
            : $token;
    }
}
