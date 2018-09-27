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
	protected $primaryKey = null;
	public $incrementing = false;
	protected $fillable = array('appraisal_form_id', 'appraisal_level_id','structure_id','assessor_group_id', 'weight_percent');
	protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}