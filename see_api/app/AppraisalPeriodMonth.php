<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppraisalPeriodMonth extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = null;
	const UPDATED_AT = null;	 
    protected $table = 'appraisal_period_month';
	protected $primaryKey = 'period_month_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	//protected $fillable = array('appraisal_year','period_no','appraisal_period_desc','appraisal_frequency_id','bonus_period_desc','bonus_frequency_id','is_bonus','salary_period_desc','salary_raise_frequency_id','is_raise','start_date','end_date');
	//protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}