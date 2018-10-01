<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class QuestionaireType extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';
    protected $table = 'questionaire_type';
	protected $primaryKey = 'questionaire_type_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	
	// protected $fillable = array('questionaire_id','questionaire_type_id','questionaire_name','pass_score','is_active');
	// protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}