<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorResource extends JsonResource
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
            'cashierId' => $this->cashierId,
            'coorId' => $this->coorId,
            'username' => $this->username,
            'fullName' => $this->fullName,
            'isActive' => $this->isActive,
            'percentage' => $this->percentage,
            'area' => $this->area,
            'created_at' => $this->created_at,
        ];
    }
}
