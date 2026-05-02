<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostEnrollRequest extends Model
{
    protected $fillable = ['host_user_id','agency_id','message','status','reviewed_by','reviewed_at','review_notes'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

  public function hostUser(){ return $this->belongsTo(User::class,'host_user_id'); }
  public function agency(){ return $this->belongsTo(Agency::class); }
}
