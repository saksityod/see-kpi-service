<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EmpJudgement extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = null;	 
    protected $table = 'emp_judgement';
	protected $primaryKey = 'emp_judgement_id';
	public $incrementing = false;
	//public $timestamps = false;
	protected $guarded = array();
}