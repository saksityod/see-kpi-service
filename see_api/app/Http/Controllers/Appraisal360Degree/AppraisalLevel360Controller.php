<?php

namespace App\Http\Controllers\Appraisal360degree;

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
	
	
	public function update_criteria(Request $request, $level_id)
	{
		try {
			$item = AppraisalLevel::findOrFail($level_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Level not found.']);
		}	
		$total_weight = 0;
		
		
		/* check set weight */
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
		
		
		if ($item->no_weight == 0) {
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
		}
		
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
