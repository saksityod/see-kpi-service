<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;

class User extends Model implements AuthenticatableContract,
                                    AuthorizableContract,
                                    CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'lportal.User_';
    protected $primaryKey = 'screenName';
    public $incrementing = false;
//  public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['username', 'password'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token'];

    public function getAuthPassword()
    {
       return $this->password_;
    }
}

class Roles extends Model  
{   
    /** 
     * The table associated with the model. 
     *  
     * @var string  
     */ 
        
    const CREATED_AT = null;    
    const UPDATED_AT = null;    
    protected $table = 'lportal.role_';   
    protected $primaryKey = null;   
    public $incrementing = true;    
    //public $timestamps = false;   
    //protected $guarded = array(); 
} 

