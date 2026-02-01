<?php

namespace App\Http\Controllers\Api\Collector;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator as FacadesValidator;

class CollectorLoginController extends BaseController
{
    //
    public function login(Request $request)
    {
        // Log::channel('stderr')->info(Auth::guard('teller')->attempt(['username' => $request->username, 'password' => $request->password]));

        if (Auth::guard('collector')->attempt(['username' => $request->username, 'password' => $request->password])) {
            $user = Auth::guard('collector')->user();



            // Log::channel('stderr')->info($user);
            // Log::channel('stderr')->info($request->deviceId);

            if( $user){
                $success['token'] =  $user->createToken('MyApp')->plainTextToken;
                $success['id'] =  $user->id;
                $success['username'] =  $user->username;
                $success['full_name'] =  $user->full_name;

                return $this->sendResponse($success, 'Teller login successfully.');

            }
            else{
                return $this->sendError('Unauthorised.', ['error' => 'Unauthorised']);
            }


        } else {
            return $this->sendError('Unauthorised.', ['error' => 'Unauthorised']);
        }
    }
}
