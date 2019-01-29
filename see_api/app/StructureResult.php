<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StructureResult extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = null;
	const UPDATED_AT = null;	 
    protected $table = 'structure_result';
	protected $primaryKey = 'structure_result_id';
	public $incrementing = true;
	public $timestamps = false;
	protected $guarded = array();
	//protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}