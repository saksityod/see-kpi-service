<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppraisalLevel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'appraisal_level';
	protected $primaryKey = 'level_id';
	public $incrementing = true;
	//public $timestamps = false;
	//protected $guarded = array();
	protected $fillable = array('appraisal_level_name','is_all_employee','is_org','is_individual','is_active','is_hr','is_self_assign','is_group_action','is_show_quality','no_weight','parent_id','district_flag','default_stage_id','seq_no', 'is_start_cal_bonus');
	protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}