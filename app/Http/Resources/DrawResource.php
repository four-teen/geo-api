<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DrawResource extends JsonResource
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
            'drawTime' => $this->drawTime,
            'l2_result' => $this->l2_result,
            'l3_result' => $this->l3_result,
            's2_result' => $this->s2_result,
            's3_result' => $this->s3_result,
            's4_result' => $this->s4_result,
            'p3_result' => $this->p3_result,
            'status' => $this->status,
            'isLocal' => $this->isLocal,
            'created_at' => $this->created_at,
        ];
    }
}
