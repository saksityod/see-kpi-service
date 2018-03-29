<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/20/17
 * Time: 3:55 PM
 */

namespace App\Model;
use Illuminate\Database\Eloquent\Model;

class CDSModel  extends Model
{
    protected $table = 'cds';
    protected $primaryKey = 'cds_id';
    public $timestamps = false;
}