<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TellerResource extends JsonResource
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
            'brgyId' => $this->brgyId,
            'username' => $this->username,
            'fullName' => $this->fullName,
            'address' => $this->address,
            'contactNumber' => $this->contactNumber,
            'location' => $this->location,
            'outlet' => $this->outlet,
            'isActive' => $this->isActive,
            'multiLogin' => $this->multiLogin,
            'supervisor' => $this->supervisor,
            'deviceId' => $this->deviceId,
            'created_at' => $this->created_at,
            
        ];
    }
}
