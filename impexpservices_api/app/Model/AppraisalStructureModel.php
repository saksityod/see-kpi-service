<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 7:11 PM
 */

namespace App\Model;
use Illuminate\Database\Eloquent\Model;

class AppraisalStructureModel extends Model
{

    protected $table = 'appraisal_structure';
    protected $primaryKey = 'structure_id';
    public $timestamps = false;
    // protected $guarded = ['seq_no'];

}