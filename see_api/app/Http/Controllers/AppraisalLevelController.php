<?php

namespace App\Http\Controllers;

use App\AppraisalLevel;
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

class AppraisalLevelController extends Controller
{

	public function __construct()
	{

	   $this->middleware('jwt.auth');
	}
	
	public function index(Request $request)
	{		
		$items = DB::select("
			SELECT a.level_id, a.appraisal_level_name, a.seq_no, a.is_all_employee, a.district_flag, a.is_org, a.is_individual, a.is_active, a.parent_id, a.is_hr, a.is_self_assign, a.is_group_action, a.is_show_quality, a.no_weight, b.appraisal_level_name parent_level_name, a.default_stage_id
			FROM appraisal_level a
			left outer join appraisal_level b
			on a.parent_id = b.level_id
			order by
			FIELD(a.level_id, 1) DESC,
			FIELD(a.is_individual, 1) DESC,
			FIELD(a.is_org, 1) DESC,
			a.seq_no ASC,
			a.level_id ASC
		");
		return response()->json($items);
	}
	
	public function store(Request $request)
	{
		if($request->is_org==0 && $request->is_individual==0) {
			$org_dividual = "required|integer|between:1,1";
			$org_dividual_text = ".between";
		} else {
			$org_dividual = "boolean";
			$org_dividual_text = "";
		}

		$validator = Validator::make($request->all(), [
			'appraisal_level_name' => 'required|max:100|unique:appraisal_level',
			'seq_no' => 'required|integer',
			'is_all_employee' => 'required|boolean',
			'is_org' => $org_dividual,
			'is_individual' => $org_dividual,
			'is_active' => 'required|boolean',
			'is_hr' => 'required|boolean',
			'is_self_assign' => 'boolean',
			'is_group_action' => 'boolean',
			'is_show_quality' => 'boolean',
			'district_flag' => 'boolean',
			'no_weight' => 'required|boolean',
			'default_stage_id' => 'integer'
		], [
			'is_org'.$org_dividual_text => 'Please select is_org or is_individual.',
			'is_individual'.$org_dividual_text => '',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item = new AppraisalLevel;
			$item->fill($request->all());
			$item->created_by = Auth::id();
			$item->updated_by = Auth::id();
			$item->save();
		}
	
		return response()->json(['status' => 200, 'data' => $item]);	
	}
	
	public function show($level_id)
	{
		try {
			$item = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}
		return response()->json($item);
	}
	
	public function update(Request $request, $level_id)
	{
		try {
			$item = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}

		if($request->is_org==0 && $request->is_individual==0) {
			$org_dividual = "required|integer|between:1,1";
			$org_dividual_text = ".between";
		} else {
			$org_dividual = "boolean";
			$org_dividual_text = "";
		}
		
		$validator = Validator::make($request->all(), [
			'appraisal_level_name' => 'required|max:100|unique:appraisal_level,appraisal_level_name,' . $level_id . ',level_id',
			'seq_no' => 'required|integer',
			'is_all_employee' => 'required|boolean',
			'is_org' => $org_dividual,
			'is_individual' => $org_dividual,
			'is_active' => 'required|boolean',
			'is_hr' => 'required|boolean',
			'is_self_assign' => 'boolean',
			'is_group_action' => 'boolean',
			'is_show_quality' => 'boolean',
			'district_flag' => 'boolean',
			'no_weight' => 'required|boolean',
			'default_stage_id' => 'integer'
		], [
			'is_org'.$org_dividual_text => 'Please select is_org or is_individual.',
			'is_individual'.$org_dividual_text => '',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item->fill($request->all());
			$item->updated_by = Auth::id();
			$item->save();
		}
	
		return response()->json(['status' => 200, 'data' => $item]);
				
	}
	
	public function destroy($level_id)
	{
		try {
			$item = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}	

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Appraisal Level is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}
		
		return response()->json(['status' => 200]);
		
	}	
	
	public function appraisal_criteria($level_id)
	{
		try {
			$ap = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}	
		
		$items = DB::select("
			SELECT a.structure_id, a.form_id, a.seq_no, a.structure_name, ifnull(b.weight_percent,0) weight_percent, if(b.appraisal_level_id is null,0,1) checkbox
			FROM appraisal_structure a
			left outer join appraisal_criteria b
			on a.structure_id = b.structure_id
			and b.appraisal_level_id = ?
			where a.is_active = 1
			order by a.seq_no		
		", array($level_id));
		
		return response()->json(['data' => $items, 'no_weight' => $ap->no_weight]);
	}
	
	public function update_criteria(Request $request, $level_id)
	{
		try {
			$item = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}	
		$total_weight = 0;
		
		
		//if ($item->no_weight == 0) {
			foreach ($request->criteria as $c) {
				if ($c['checkbox'] == 1) {
					$total_weight += $c['weight_percent'];

					$form_type = DB::select("
						select form_id
						from appraisal_structure 
						where structure_id = '".$c['structure_id']."'
						");

					if($form_type[0]->form_id!=3) {
						if ($c['weight_percent'] == 0) {
							return response()->json(['status' => 400, 'data' => 'Selected weight percent cannot be set to 0']);
						}
					}
				}
			}
			
			if ($total_weight != 100) {
				return response()->json(['status' => 400, 'data' => 'Total weight is not equal to 100%']);
			}
		//}
		
		foreach ($request->criteria as $c) {
			if ($c['checkbox'] == 1) {
				$criteria = AppraisalCriteria::where('appraisal_level_id',$level_id)->where('structure_id',$c['structure_id']);
				if ($criteria->count() > 0) {
					AppraisalCriteria::where('appraisal_level_id',$level_id)->where('structure_id',$c['structure_id'])->update(['weight_percent' => $c['weight_percent'], 'updated_by' => Auth::id()]);
				} else {
					$item = new AppraisalCriteria;
					$item->appraisal_level_id = $level_id;
					$item->structure_id = $c['structure_id'];
					$item->weight_percent = $c['weight_percent'];
					$item->created_by = Auth::id();
					$item->updated_by = Auth::id();
					$item->save();
				}
			} else {
				AppraisalCriteria::where('appraisal_level_id',$level_id)->where('structure_id',$c['structure_id'])->delete();
			}
		}
		
		return response()->json(['status' => 200]);
		
	}
}
