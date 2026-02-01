<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BettSettingResource extends JsonResource
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
            'coorId' => $this->coorId,
            'betNo' => $this->betNo,
            'limit' => $this->limit,
            'type' => $this->type,
        ];
    }
}
