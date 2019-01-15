<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppraisalForm extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */

	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'update_dttm';
    protected $table = 'appraisal_form';
	protected $primaryKey = 'appraisal_form_id';
	public $incrementing = true;

    protected $guarded = array();
	// protected $fillable = array('appraisal_form_name', 'is_bonus', 'is_raise', 'is_mpi', 'is_active');
	protected $hidden = ['created_by', 'update_by', 'created_dttm', 'update_dttm'];
}
