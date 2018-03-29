<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/20/17
 * Time: 4:24 PM
 */

namespace App\Model;
use Illuminate\Database\Eloquent\Model;

class AppraisalItemModel  extends Model
{
    protected $table = 'appraisal_item';
    protected $primaryKey = 'item_id';
    public $timestamps = false;
}