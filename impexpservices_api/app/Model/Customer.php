<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    //
    protected $table = 'CUSTOMER';
    protected $primaryKey = 'cid';
    public $timestamps = false;

}
