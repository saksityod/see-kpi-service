<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppraisalLevel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
	 
	const CREATED_AT = 'created_dttm';
	const UPDATED_AT = 'updated_dttm';	 
    protected $table = 'appraisal_level';
	protected $primaryKey = 'level_id';
	public $incrementing = true;
	//public $timestamps = false;
	//protected $guarded = array();
	protected $fillable = array('appraisal_level_name','is_all_employee','is_org','is_individual','is_active','is_hr','is_self_assign','is_group_action','is_show_quality','no_weight','parent_id','district_flag','default_stage_id','seq_no');
	protected $hidden = ['created_by', 'updated_by', 'created_dttm', 'updated_dttm'];

	public static function getTree($id, $tree = []) {
		$lowestLevel = AppraisalLevel::select("level_id", "parent_id")->where('level_id', $id)->first();

		if (empty($lowestLevel)) {
			return $tree;
		}

		$tree[] = $lowestLevel->toArray();

		if ($lowestLevel->parent_id !== 0) {
			$tree = AppraisalLevel::getTree($lowestLevel->parent_id, $tree);
		}

		return $tree;
	}
}