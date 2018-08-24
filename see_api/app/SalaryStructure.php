<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SalaryStructure extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	// const CREATED_AT = 'created_dttm';
	// const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'salary_structure';
	protected $primaryKey = 'appraisal_year';
	// public $incrementing = true;
	//public $timestamps = false;
	//protected $guarded = array();
	protected $fillable = array('level_id','step','s_amount','is_active');
	// protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}