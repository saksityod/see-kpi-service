<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CompetencyResult extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */

	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';
    protected $table = 'competency_result';
	protected $primaryKey = 'competency_result_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	//protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}
