<?php

namespace App\Http\Controllers\Salary;

use App\EmpResult;
use App\EmpJudgement;

use Auth;
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

class JudgementController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	public function list_status() 
	{
		$items = DB::select("
			SELECT judgement_status_id, judgement_status
			FROM judgement_status
			WHERE is_active = 1
		");

		return response()->json($items);
	}


	public function index(Request $request) 
	{
		$year = empty($request->year) ? "" : "AND ap.appraisal_year = '{$request->year}'";
		$period = empty($request->period) ? "" : "AND ap.period_id = '{$request->period}'";
		$status = empty($request->status) ? "" : "AND er.judgement_status_id = '{$request->status}'";

		$items = DB::select("
			SELECT er.emp_result_id,
					e.emp_code,
					e.emp_name,
					al.appraisal_level_name,
					o.org_name,
					p.position_name,
					j.judgement_status_id,
					j.judgement_status,
					CONCAT(ap.appraisal_period_desc,' Start Date: ', ap.start_date,' End Date: ', ap.end_date) appraisal_period_desc
			FROM emp_result er
			LEFT OUTER JOIN appraisal_period ap ON ap.period_id = er.period_id
			LEFT OUTER JOIN employee e ON e.emp_id = er.emp_id
			LEFT OUTER JOIN appraisal_level al on al.level_id = er.level_id
			LEFT OUTER JOIN org o ON o.org_id = er.org_id
			LEFT OUTER JOIN position p ON p.position_id = er.position_id
			LEFT OUTER JOIN appraisal_grade ag ON ag.grade_id = er.salary_grade_id AND ag.is_judgement = 1
			INNER JOIN judgement_status j ON j.judgement_status_id = er.judgement_status_id
			WHERE 1=1 ". $year . $period . $status ."
			ORDER BY j.judgement_status_id, er.emp_result_id
			");

		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);

		return response()->json($result);
	}


	public function assign_judgement(Request $request) 
	{
		$emp_result_id = "";
		$eri = json_decode($request['emp_result_id'], true);
		$last_key = count($eri) -1;
		foreach ($eri as $eri_k => $d) {
			if($eri_k == $last_key) {
				$emp_result_id .= $d['id'];
			} else {
				$emp_result_id .= $d['id'].",";
			}
		}
		
		if(count($eri) > 1) {
			$head = [];
			$detail = DB::select("
				SELECT j.judgement_item_id, j.judgement_item_name, '0' is_pass
				FROM judgement_item j
				WHERE j.is_active = 1
				ORDER BY j.judgement_item_id
			");
		} else {
			$head = DB::select("
				SELECT mq.*, CONCAT(ROUND(mq.avg_result_score, 2),' (',mq.salary_raise_step, ' ขั้น)') grand_total
				FROM (
					SELECT e.emp_code, 
						e.emp_name, 
						p.position_name, 
						o.org_name, 
						chief.emp_code chief_emp_code, 
						chief.emp_name chief_emp_name,
						ap.appraisal_period_desc,
						ag.salary_raise_step,
						(
							SELECT AVG(er.result_score)
							FROM emp_result er 
							INNER JOIN (
								SELECT emp_id, org_id, position_id, level_id, appraisal_type_id
								FROM emp_result ser 
								WHERE emp_result_id IN ({$emp_result_id})
							) jer ON jer.emp_id = er.emp_id AND jer.org_id = er.org_id
								AND jer.position_id = er.position_id AND jer.level_id = er.level_id
								AND jer.appraisal_type_id = er.appraisal_type_id
							WHERE er.period_id IN(
								SELECT DISTINCT ap.period_id
								FROM appraisal_period ap 
								INNER JOIN appraisal_period aps ON aps.salary_period_desc = ap.salary_period_desc
								WHERE aps.period_id = (
									SELECT period_id FROM emp_result WHERE emp_result_id = '{$emp_result_id}'
								)
							)
						) avg_result_score						
					FROM emp_result er
					LEFT OUTER JOIN appraisal_period ap ON ap.period_id = er.period_id
					LEFT OUTER JOIN employee e ON e.emp_id = er.emp_id
					LEFT OUTER JOIN employee chief ON chief.emp_code = e.chief_emp_code
					LEFT OUTER JOIN org o ON o.org_id = er.org_id
					LEFT OUTER JOIN position p ON p.position_id = er.position_id
					LEFT OUTER JOIN appraisal_grade ag ON ag.grade_id = er.salary_grade_id AND ag.is_judgement = 1
					WHERE er.emp_result_id IN ({$emp_result_id})
				)mq
			");

			$detail = DB::select("
				SELECT j.judgement_item_id, j.judgement_item_name, IFNULL(ej.is_pass,0) is_pass
				FROM judgement_item j
				LEFT OUTER JOIN emp_judgement ej ON ej.judgement_item_id = j.judgement_item_id 
				AND ej.emp_result_id IN ({$emp_result_id})
				WHERE j.is_active = 1
				ORDER BY j.judgement_item_id
			");
		} 

		return response()->json(['head' => $head, 'detail' => $detail]);
	}


	public function store(Request $request) 
	{
		$emp_result_id = "";
		//$eri = json_decode($request['emp_result_id'], true);
		$eri = $request['emp_result_id'];
		$last_key = count($eri) -1;
		foreach ($eri as $eri_k => $d) {
			if($eri_k == $last_key) {
				$emp_result_id .= $d['id'];
			} else {
				$emp_result_id .= $d['id'].",";
			}
		}
		
		//$request['items'] = json_decode($request['items'], true);
		if(empty($request['items'])) {
			return response()->json(['status' => 404, 'errors' => 'Array items not found.']);
		}
		
		DB::beginTransaction();
		$errors = [];

		foreach ($request['items'] as $i) {
			$validator = Validator::make([
				'judgement_item_id' => $i['judgement_item_id'],
				'is_pass' => $i['is_pass']
			], [
				'judgement_item_id' => 'required|integer',
				'is_pass' => 'required|integer'
			]);

			if($validator->fails()) {
				return response()->json(['status' => 400, 'errors' => $validator->errors()]);
			}
		}

		try {
			foreach ($eri as $d) {
				DB::table('emp_judgement')->where('emp_result_id', '=', $d['id'])->delete();
				$ej = EmpResult::find($d['id']);
				$ej->judgement_status_id = 2;
				$ej->updated_by = Auth::id();
				$ej->save();
			}
			
			foreach ($request['items'] as $i) {
				foreach ($eri as $d) {
					$ej = new EmpJudgement;
					$ej->emp_result_id = $d['id'];
					$ej->judgement_item_id = $i['judgement_item_id'];
					$ej->is_pass = $i['is_pass'];
					$ej->created_by = Auth::id();
					$ej->save();
				}
			}
		}
		catch (Exception $e) {
			$errors[] = ['errors' => $e.'.'];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;
        return response()->json(['status' => $status, 'errors' => $errors]);
	}

	
}