<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UsersRoles extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = null;
	const UPDATED_AT = null;
    protected $table = 'lportal.users_roles';
	protected $primaryKey = null;
	public $incrementing = true;
	//public $timestamps = false;
	//protected $guarded = array();
}