<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class JobCode extends Model
{
	 /**
     * The table associated with the model.
     *
     * @var string
     */

    const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'job_code';
	protected $primaryKey = 'job_code';
	public $incrementing = false;
}
