<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Helpers\BaseController as BaseController;
use Validator;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator as FacadesValidator;

class AdminController extends BaseController
{
    //

    public function register(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            'name'=>'required',
            'email'=>'required',
            'password'=>'required',
            'role' => 'nullable|in:administrator,user',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $input['role'] = $input['role'] ?? 'user';
        $input['is_active'] = $input['is_active'] ?? true;
        $input['barangay_scope'] = $input['barangay_scope'] ?? 'ALL';
        $user = User::create($input);
        $success['token'] = $this->normalizeAccessToken($user->createToken('MyApp')->plainTextToken);
        $success['name'] = $user->name;
        $success['id'] = $user->id;
        $success['role'] = $user->role;
        $success['is_active'] = (bool) $user->is_active;
        $success['barangay_scope'] = $user->barangay_scope;
        $success['permission_codes'] = [];
        $success['barangay_ids'] = [];

        return $this->sendResponse($success, 'User register successfully.');
    }

    public function login(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        if(Auth::guard('web')->attempt(['email' => $request->email, 'password' => $request->password])){
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
            $success['email'] = $user->email;
            $success['role'] = $user->role;
            $success['is_active'] = (bool) $user->is_active;
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
        else{
            return $this->sendError('Unauthorised.', ['error'=>'Unauthorised']);
        }
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
