<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EmpResultJudgement extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = null;	 
    protected $table = 'emp_result_judgement';
	protected $primaryKey = 'emp_result_judgement_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	protected $hidden = ['created_by', 'created_dttm'];
}