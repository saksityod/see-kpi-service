<?php

namespace App\Http\Controllers\Appraisal360Degree;

use App\CompetencyCriteria;
use App\AppraisalCriteria;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CompetencyCriteriaController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	public function show(Request $request) {
		$items = DB::select("
			select ag.*, cc.appraisal_level_id, cc.structure_id, ifnull(cc.weight_percent,0) weight_percent, if(cc.appraisal_level_id is null,0,1) checkbox
			from assessor_group ag
			left outer join competency_criteria cc on cc.assessor_group_id = ag.assessor_group_id
			and cc.appraisal_level_id ={$request->appraisal_level_id}
			and cc.structure_id = {$request->structure_id}
		");

		return response()->json($items);
	}

	public function update(Request $request, $appraisal_level_id, $structure_id)
	{
		// Insert appraisal_criteria if not exists //
		$appCriteria = AppraisalCriteria::where('appraisal_level_id', $appraisal_level_id)->where('structure_id', $structure_id);
		if ($appCriteria->count() == 0) {
			$criteria = new AppraisalCriteria();
			$criteria->appraisal_level_id = $appraisal_level_id;
			$criteria->structure_id = $structure_id;
			$criteria->weight_percent = 00.00;
			$criteria->created_by = Auth::id();
			$criteria->updated_by = Auth::id();
			$criteria->save();
		}


		try {
			$item = CompetencyCriteria::where('appraisal_level_id',$appraisal_level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Competency Criteria not found.']);
		}	
		$total_weight = 0;
		
		foreach ($request->set_weight as $c) {
			if ($c['checkbox'] == 1) {
				$total_weight += $c['weight_percent'];
			}
		}

		if ($total_weight != 100) {
			return response()->json(['status' => 400, 'data' => 'Total weight is not equal to 100%']);
		}
		
		foreach ($request->set_weight as $c) {
			if ($c['checkbox'] == 1) {
				$competency = CompetencyCriteria::where('appraisal_level_id',$appraisal_level_id)->where('structure_id',$c['structure_id'])->where('assessor_group_id',$c['assessor_group_id']);
				if ($competency->count() > 0) {
					CompetencyCriteria::where('appraisal_level_id',$appraisal_level_id)->where('structure_id',$c['structure_id'])->where('assessor_group_id',$c['assessor_group_id'])->update(['weight_percent' => $c['weight_percent'], 'updated_by' => Auth::id()]);
				} else {
					$item = new CompetencyCriteria;
					$item->appraisal_level_id = $appraisal_level_id;
					$item->structure_id = $c['structure_id'];
					$item->assessor_group_id = $c['assessor_group_id'];
					$item->weight_percent = $c['weight_percent'];
					$item->created_by = Auth::id();
					$item->updated_by = Auth::id();
					$item->save();
				}
			} else {
				CompetencyCriteria::where('appraisal_level_id',$appraisal_level_id)->where('structure_id',$c['structure_id'])->where('assessor_group_id',$c['assessor_group_id'])->delete();
			}
		}
		
		return response()->json(['status' => 200]);
		
	}
}
