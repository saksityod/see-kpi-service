<?php

namespace App\Http\Controllers\Appraisal360degree;

use App\CompetencyCriteria;

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
		try {
			$item = CompetencyCriteria::findOrFail($request->appraisal_level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Competency Criteria not found.']);
		}

		$items = DB::select("
			select cc.*, ag.assessor_group_name
			from competency_criteria cc
			right join assessor_group ag on ag.assessor_group_id = cc.assessor_group_id
			where cc.structure_id = {$request->structure_id}
			and cc.appraisal_level_id = {$request->appraisal_level_id}
		");

		return response()->json($items);
	}

	public function update(Request $request, $appraisal_level_id)
	{
		try {
			$item = CompetencyCriteria::findOrFail($appraisal_level_id);
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
