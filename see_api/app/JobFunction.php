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
    const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	
    protected $table = 'job_function';
    protected $primaryKey = 'job_function_id';
    public $incrementing = true;
    //public $timestamps = false;
    protected $guarded = array();

    protected $fillable = array('job_function_id','job_function_name','is_evaluated,is_show_report','job_function_group_id');
    protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
	
}
