<?php

namespace App\Http\Controllers\Appraisal360Degree;

use App\Http\Controllers\Controller;
use App\AppraisalCriteria;
use App\AppraisalLevel;
use App\AppraisalStructure;
use App\CompetencyCriteria;

use Auth;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class AppraisalLevel360Controller extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}
	
	
	public function appraisal_criteria($level_id)
	{
		try {
			$ap = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}	
		
		$strucObj = DB::select("
			SELECT 
				b.appraisal_level_id,
				(
					SELECT sal.appraisal_level_name FROM appraisal_level sal
					WHERE sal.level_id = b.appraisal_level_id
				) as appraisal_level_name,
				a.structure_id, 
				a.form_id, 
				a.seq_no, 
				a.structure_name, 
				ifnull(b.weight_percent,0) weight_percent, 
				if(b.appraisal_level_id is null,0,1) checkbox
			FROM appraisal_structure a
			LEFT OUTER JOIN appraisal_criteria b ON a.structure_id = b.structure_id
			AND b.appraisal_level_id = ?
			WHERE a.is_active = 1
			ORDER BY a.seq_no	
		", array($level_id));
	
		return response()->json(['data' => $strucObj, 'no_weight' => $ap->no_weight]);
	}
	
	
	public function update_criteria(Request $request, $level_id)
	{		
		// Check set weight //
		foreach ($request->criteria as $c){
			if ($c['checkbox'] == 1) {
				$struc = AppraisalStructure::find($c['structure_id']);
				if($struc->form_id == 2){
					$compCriteria = CompetencyCriteria::where('appraisal_level_id',$level_id)->where('structure_id',$struc->structure_id);
					if($compCriteria->count() == 0){
						return response()->json(['status' => 400, 'data' => 'Data can not be saved. It has not set the weight.']);
					}
				}
			}
		}


		// Check Total Weight & No Weight //
		$total_weight = 0;
		$itemIsActiveCnt = 0;
		// --> Get level info by level id //
		try {
			$appLevel = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}
		// --> Check No Weight //
		if ($appLevel->no_weight == 0) {
			foreach ($request->criteria as $c) {
				if ($c['checkbox'] == 1) {
					$itemIsActiveCnt += 1;
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
		}
		// --> Check the total weight score is 100% //
		if ($itemIsActiveCnt > 0 && $total_weight != 100) {
			return response()->json(['status' => 400, 'data' => 'Total weight is not equal to 100%']);
		}
		

		// Insert - Update - Delete item inactive //
		foreach ($request->criteria as $c) {
			if ($c['checkbox'] == 1) {
				$criteria = AppraisalCriteria::where('appraisal_level_id',$level_id)
					->where('structure_id',$c['structure_id']);
				if ($criteria->count() > 0) {
					AppraisalCriteria::where('appraisal_level_id',$level_id)
						->where('structure_id',$c['structure_id'])
						->update([
								'weight_percent' => $c['weight_percent'], 
								'updated_by' => Auth::id()]);
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
				CompetencyCriteria::where('appraisal_level_id', $level_id)
					->where('structure_id', $c['structure_id'])
					->delete();

				AppraisalCriteria::where('appraisal_level_id', $level_id)
					->where('structure_id', $c['structure_id'])
					->delete();
			}
		}
		
		return response()->json(['status' => 200]);
		
	}
}
