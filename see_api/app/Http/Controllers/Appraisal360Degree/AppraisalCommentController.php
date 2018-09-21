<?php

namespace App\Http\Controllers\Appraisal360degree;

use App\SystemConfiguration;
use App\AssessmentOpinion;

use Auth;
use DateTime;
use DB;
use File;
use Validator;
use Excel;
use Config;
use Mail;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalCommentController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	public function show(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
		//return Auth::id();
		$authen = DB::select("SELECT emp.emp_id, emp.emp_code, u.is_all_employee
			FROM emp_result em
			INNER JOIN employee emp ON em.emp_id = emp.emp_id
			CROSS JOIN (SELECT e.emp_id, e.emp_code, l.is_all_employee FROM employee e
				INNER JOIN appraisal_level l ON e.level_id = l.level_id
				WHERE e.emp_code = ?) u
			WHERE em.emp_result_id = ?"
		,array(Auth::id(), $request->emp_result_id));


		foreach($authen as $au){

			if($au->is_all_employee == 1){
				$items = DB::select("
					SELECT ao.opinion_id
					, ao.emp_result_id
					, em.emp_id
					, CONCAT('#',ag.assessor_group_id,ao.emp_result_id,em.emp_id,' (',ag.assessor_group_name,')') as emp_name
					, ag.assessor_group_id
					, (CASE WHEN LENGTH(ao.emp_strength_opinion) and  LENGTH(ao.emp_weakness_opinion)
					THEN 'yes' ELSE 'no' END) AS comment
					, ao.assessor_strength_opinion
					, ao.assessor_weakness_opinion
					, ao.emp_strength_opinion
					, ao.emp_weakness_opinion
					-- , 'admin' as user
					FROM assessment_opinion ao
					INNER JOIN employee em ON ao.assessor_id = em.emp_id
					INNER JOIN assessor_group ag ON ao.assessor_group_id = ag.assessor_group_id
					WHERE ao.emp_result_id = ?
					ORDER BY ag.assessor_group_id ASC, em.emp_id ASC
				",array($request->emp_result_id));

				$user = 'admin';

				if(empty($items[0]->opinion_id)){
					return response()->json(['status' => 400, 'data' => 'admin-empty']);
				}
			}
			else if ($au->emp_code == Auth::id()){
				$items = DB::select("
					SELECT ao.opinion_id
					, ao.emp_result_id
					, em.emp_id
					, CONCAT('#',ag.assessor_group_id,ao.emp_result_id,em.emp_id,' (',ag.assessor_group_name,')') as emp_name
					, ag.assessor_group_id
					, (CASE WHEN LENGTH(ao.emp_strength_opinion) and  LENGTH(ao.emp_weakness_opinion)
					THEN 'yes' ELSE 'no' END) AS comment
					, ao.assessor_strength_opinion
					, ao.assessor_weakness_opinion
					, ao.emp_strength_opinion
					, ao.emp_weakness_opinion
					-- , 'my' as user
					FROM assessment_opinion ao
					INNER JOIN employee em ON ao.assessor_id = em.emp_id
					INNER JOIN assessor_group ag ON ao.assessor_group_id = ag.assessor_group_id
					WHERE ao.emp_result_id = ?
					ORDER BY ag.assessor_group_id ASC, em.emp_id ASC
				",array($request->emp_result_id));

					$user = 'my';

				if(empty($items[0]->opinion_id)){
					return response()->json(['status' => 400, 'data' => 'my-empty']);
				}
			}
			else{
				$items = DB::select("
					SELECT ao.opinion_id
					, ao.emp_result_id
					, em.emp_id
					, CONCAT('#',ag.assessor_group_id,ao.emp_result_id,em.emp_id,' (',ag.assessor_group_name,')') as emp_name
					, ag.assessor_group_id
					, (CASE WHEN LENGTH(ao.emp_strength_opinion) and  LENGTH(ao.emp_weakness_opinion)
					THEN 'yes' ELSE 'no' END) AS comment
					, ao.assessor_strength_opinion
					, ao.assessor_weakness_opinion
					, ao.emp_strength_opinion
					, ao.emp_weakness_opinion
					-- , 'other' as user
					FROM assessment_opinion ao
					INNER JOIN employee em ON ao.assessor_id = em.emp_id
					INNER JOIN assessor_group ag ON ao.assessor_group_id = ag.assessor_group_id
					WHERE ao.emp_result_id = ?
					AND em.emp_code = ?
					ORDER BY ag.assessor_group_id ASC, em.emp_id ASC
				",array($request->emp_result_id, Auth::id()));

				if(empty($items[0]->opinion_id)){
					$items = DB::select("
						select  0 as opinion_id
						, ? as emp_result_id -- ?
						, emp_id
						, CONCAT('#',ag.assessor_group_id,?,emp_id,' (',ag.assessor_group_name,')') as emp_name
						, ? as assessor_group_id -- ?
						, 'no' as comment
						, '' as assessor_strength_opinion
						, '' as assessor_weakness_opinion
						, '' as emp_strength_opinion
						, '' as emp_weakness_opinion
						-- , 'other' as user
						from employee
						cross join (select assessor_group_name, assessor_group_id from assessor_group where assessor_group_id = ?) ag
						where emp_code = ? "
					, array($request->emp_result_id, $request->emp_result_id, $request->assessor_group_id
					, $request->assessor_group_id, Auth::id()));
					//return response()->json(['status' => 400, 'data' => 'other-empty']);
				}

				$user = 'other';
			}

			$groups = array();
			$detail = array();
			foreach ($items as $i) {
				$detail[] = $i;
			}
			$groups = ['detail' => $detail, 'user' => $user];

			return response()->json($groups);
		}//end foreach authen
	}//end function

	public function insert_update(Request $request){

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$now = new DateTime();
		$data = 0;
		$datas = json_decode($request->data);

		foreach ($datas as $da) {

			$assessment = AssessmentOpinion::find($da->opinion_id);
			if(empty($assessment)){
				//insert
				$assessment = new AssessmentOpinion;
				$assessment->emp_result_id = $da->emp_result_id;
				$assessment->assessor_group_id = 3; //$da->assessor_group_id;
				$assessment->assessor_id = $da->emp_id;
				$assessment->assessor_strength_opinion = $da->assessor_strength_opinion;
				$assessment->assessor_weakness_opinion = $da->assessor_weakness_opinion;
				$assessment->emp_strength_opinion = $da->emp_strength_opinion;
				$assessment->emp_weakness_opinion = $da->emp_weakness_opinion;
				$assessment->created_by = Auth::id();
				$assessment->created_dttm = $now;
				$assessment->save();

				$assess = AssessmentOpinion::find($da->opinion_id);
				if(!empty($assess)){
						$data += 1;
				}if(empty($assess)){
						$data = 0;
				}
			}
				//update
				$assessment->emp_result_id = $da->emp_result_id;
				$assessment->assessor_group_id = $da->assessor_group_id;
				$assessment->assessor_id = $da->emp_id;
				$assessment->assessor_strength_opinion = $da->assessor_strength_opinion;
				$assessment->assessor_weakness_opinion = $da->assessor_weakness_opinion;
				$assessment->emp_strength_opinion = $da->emp_strength_opinion;
				$assessment->emp_weakness_opinion = $da->emp_weakness_opinion;
				$assessment->updated_by = Auth::id();
				$assessment->updated_dttm = $now;
				$assessment->save();
		}//end foreach

		if($data == 0){
			return response()->json(['status' => 400, 'data' => 'not insert data']);
		}else{
			return response()->json(['status' => 200]);
		}
	}//end function

}
