<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CoordinatorResource extends JsonResource
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
            'username' => $this->username,
            'fullName' => $this->fullName,
            'areas' => $this->areas,
            'percentage' => $this->percentage,
            'isActive' => $this->isActive,
            'l2_limit' => $this->l2_limit,
            'l3_limit' => $this->l3_limit,
            's2_limit' => $this->s2_limit,
            's3_limit' => $this->s3_limit,
            'd4_limit' => $this->d4_limit,
            'p3_limit' => $this->p3_limit,
            'created_at' => $this->created_at,
        ];
    }
}
