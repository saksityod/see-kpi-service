<?php

namespace App\Http\Controllers\Salary;

use App\EmpResult;
use app\EmpJudgement;

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

	public function list_status() {
		$items = DB::select("
			SELECT judgement_status_id, judgement_status
			FROM judgement_status
			WHERE is_active = 1
		");

		return response()->json($items);
	}

	public function index(Request $request) {
		$year = empty($request->year) ? "" : "AND ap.appraisal_year = '{$request->year}'";
		$period = empty($request->period) ? "" : "AND ap.period = '{$request->period}'";
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
			LEFT OUTER JOIN appraisal_grand ag ON ag.grade_id = er.salary_grade_id AND ag.is_judgement = 1
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

	public function assign_judgement(Request $request) {
		$emp_result_id = [];
		foreach ($request['emp_result_id'] as $key => $value) {
			$emp_result_id[] = $value['id'];
		}

		try {
			EmpResult::firstOrFail($emp_result_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'errors' => 'EmpResult not found in DB.']);
		}

		if(count($emp_result_id) > 1) {
			$head = [];
			$detail = DB::select("
				SELECT j.judgement_item_id, j.judgement_item_name, '0' is_pass
				FROM judgement_item j
				WHERE j.is_active = 1
				ORDER BY j.judgement_item_id
			");
		} else {
			$head = DB::select("
				SELECT e.emp_code, 
						e.emp_name, 
						p.position_name, 
						o.org_name, 
						chief.emp_code chief_emp_code, 
						chief.emp_name chief_emp_name,
						ap.appraisal_period_desc,
						CONCAT(er.result_score,' (',ag.grade_name, ')') grand_total
				FROM emp_result er
				LEFT OUTER JOIN appraisal_period ap ON ap.period_id = er.period_id
				LEFT OUTER JOIN employee e ON e.emp_id = er.emp_id
				LEFT OUTER JOIN employee chief ON chief.emp_code = e.chief_emp_code
				LEFT OUTER JOIN org o ON o.org_id = er.org_id
				LEFT OUTER JOIN position p ON p.position_id = er.position_id
				LEFT OUTER JOIN appraisal_grand ag ON ag.grade_id = er.salary_grade_id AND ag.is_judgement = 1
				WHERE er.emp_result_id IN ({$emp_result_id})
			");

			$detail = DB::select("
				SELECT j.judgement_item_id, j.judgement_item_name, IF(ej.is_pass IS NULL, 0, 1) is_pass
				FROM judgement_item j
				LEFT OUTER JOIN emp_judgement ej ON ej.judgement_item_id = j.judgement_item_id 
				AND ej.emp_result_id IN ({$emp_result_id})
				WHERE j.is_active = 1
				ORDER BY j.judgement_item_id
			");
		}

		return response()->json(['head' => $head, 'detail' => $detail]);
	}

	public function store(Request $request) {
		$emp_result_id = [];
		foreach ($request['emp_result_id'] as $key => $value) {
			$emp_result_id[] = $value['id'];
		}

		try {
			EmpResult::firstOrFail($emp_result_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'errors' => 'EmpResult not found in DB.']);
		}

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
			foreach ($emp_result_id as $id) {
				$ej = EmpResult::find($id);
				$ej->judgement_status_id = 2;
				$ej->updated_by = Auth::id();
				$ej->save();
			}
			
			DB::table('emp_judgement')->whereIn('emp_result_id', '=', $emp_result_id)->delete();
			foreach ($request['items'] as $i) {
				if($i['is_pass']==1) {
					foreach ($emp_result_id as $id) {
						$ej = new EmpJudgement;
						$ej->emp_result_id = $id;
						$ej->judgement_item_id = $i['judgement_item_id'];
						$ej->is_pass = $i['is_pass'];
						$ej->created_by = Auth::id();
						$ej->save();
					}
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
