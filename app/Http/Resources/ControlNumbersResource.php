<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ControlNumbersResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'betNo' => $this->betNo,
            'bet_limit' => $this->bet_limit,
            'betCode' => $this->betCode,
            'accountantId' => $this->accountantId,
            'created_at' => $this->created_at,
        ];
    }
}
