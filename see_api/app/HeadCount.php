<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class HeadCount extends Model
{
     /**
     * The table associated with the model.
     *
     * @var string
     */
    const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	
    protected $table = 'head_count';
    protected $primaryKey = 'head_count_id';
    public $incrementing = true;
    //public $timestamps = false;
    protected $guarded = array();

    //protected $fillable = array('valid_date','position_id','job_function_id,head_count,');
    //protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
	
}
