<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EmployeeSnapshot extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = null;	 
    protected $table = 'employee_snapshot';
	protected $primaryKey = 'emp_snapshot_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	
	// protected $fillable = array('employee_snapshot_id','start_date','emp_id','emp_code','industry_class');
	protected $hidden = ['created_by', 'created_dttm'];
}