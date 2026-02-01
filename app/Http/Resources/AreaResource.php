<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AreaResource extends JsonResource
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
            'name' => $this->name,
            // 'tellerId' => $this->tellerId,
            // 'drawId' => $this->drawId,
            // 'betCode' => $this->betCode,
            // 'betNo' => $this->betNo,
            // 'betAmount' => $this->betAmount,
            // 'winAmount' => $this->winAmount,
            'created_at' => $this->created_at,
        ];
    }
}
