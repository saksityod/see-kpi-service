<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CustomerPosition extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = null;
	const UPDATED_AT = null;
    protected $table = 'customer_position';
	protected $primaryKey = 'customer_position_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	
	protected $fillable = array('customer_position_id','customer_id','position_code');
	// protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}