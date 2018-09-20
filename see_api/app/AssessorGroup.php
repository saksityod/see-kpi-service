<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AssessorGroup extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	 
	const CREATED_AT = null;
	const UPDATED_AT = null;	 
    protected $table = 'assessor_group';
	protected $primaryKey = 'assessor_group_id';
	public $incrementing = false;
	//public $timestamps = false;
	//protected $guarded = array();
	protected $fillable = array('assessor_group_id', 'assessor_group_name');
}