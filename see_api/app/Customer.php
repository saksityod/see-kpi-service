<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'customer';
	protected $primaryKey = 'customer_id';
	public $incrementing = true;
	//public $timestamps = false;
	protected $guarded = array();
	
	protected $fillable = array('customer_id','customer_code','customer_name','customer_type','industry_class');
	protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];
}