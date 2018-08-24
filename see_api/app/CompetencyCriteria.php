<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CompetencyCriteria extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'competency_criteria';
	protected $primaryKey = 'appraisal_level_id';
	// public $incrementing = true;
	//public $timestamps = false;
	//protected $guarded = array();
	protected $fillable = array('appraisal_level_id','assessor_group_id','weight_percent');
	protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}