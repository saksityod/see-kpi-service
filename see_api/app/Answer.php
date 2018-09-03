<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';
    protected $table = 'answer';
	protected $primaryKey = 'answer_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	
	// protected $fillable = array('questionnaire_id','customer_code','customer_name','customer_type','industry_class');
	// protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}