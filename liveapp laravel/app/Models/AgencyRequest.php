<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyRequest extends Model
{
    protected $fillable = [
        'user_id','agency_name','legal_name','contact_phone','website','about',
        'status','reviewed_by','reviewed_at','review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function user(){ return $this->belongsTo(User::class); }
}
