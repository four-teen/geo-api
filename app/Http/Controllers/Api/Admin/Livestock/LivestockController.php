<?php

namespace App\Http\Controllers\Api\admin\Livestock;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Livestock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;



class LivestockController extends BaseController
{
    //
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = livestocks::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Area created successfully.');
    }
}
