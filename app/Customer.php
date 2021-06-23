<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    //use SoftDeletes;

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $fillable = [
        'parent_id',
        'ref_id',
        'register',
        'code',
        'password',
        'name',
        'last_name',
        'phone',
        'phone2',
        'email',
        'address',
        'address2',
        'type',
        'status',
        'created_at',
        'updated_at',
        'deleted_at',
        'description',
        'activation_type_id',
        'activation_at',
    ];

    public function scopeFilterInput($query)
    {
        if(request()->input('status')!=""){
            $status = request()->input('status', "active"); 

            return $query->where('customers.status', $status);
        }else{
            return $query->where('customers.status', 'active')
            ->orWhere('customers.status', '=', 'pending');
        }
    }
}
