<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class JobFunction extends Model
{
     /**
     * The table associated with the model.
     *
     * @var string
     */
    
    protected $table = 'job_function';
    protected $primaryKey = 'job_function_id';
    public $incrementing = false;
	public $timestamps = false;
}
