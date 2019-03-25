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
    public $incrementing = true;
    public $timestamps = false;
    protected $fillable = array('job_function_id','job_function_name','is_evaluated,is_headcount');
	
}
