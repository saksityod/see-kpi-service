<?php

namespace App\Http\Controllers\PMTL;
use App\Http\Controllers\PMTL\QuestionaireDataController;

use App\Employee;
use App\EmployeeSnapshot;
use App\AppraisalLevel;
use App\Position;
use App\Org;

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

class VsmEmployeeController extends Controller
{
	protected $qdc_service;
	public function __construct(QuestionaireDataController $qdc_service)
	{
	   $this->middleware('jwt.auth');
	   $this->qdc_service = $qdc_service;
	}

	public function auto_start_date(Request $request) {
		$items = DB::select("
			SELECT DISTINCT DATE_FORMAT(es.start_date,'%d/%m/%Y') start_date
			FROM employee_snapshot es
			INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
			WHERE es.start_date LIKE '%{$request->start_date}%'
			AND jf.job_function_name = 'VSM'
			LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_emp(Request $request) {
		$request->start_date = $this->qdc_service->format_date($request->start_date);
		$emp_name = $this->qdc_service->concat_emp_first_last_code($request->emp_name);
		
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";

		$items = DB::select("
			SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name,' ',es.emp_last_name) emp_name
			FROM employee_snapshot es
			INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
			INNER JOIN position p ON p.position_id = es.position_id
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			)
			AND jf.job_function_name = 'VSM'
			".$start_date."
			LIMIT 10
		");
		return response()->json($items);
	}

	public function index(Request $request)
	{
		$request->start_date = $this->qdc_service->format_date($request->start_date);
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND es.emp_snapshot_id = '{$request->emp_snapshot_id}'";

		$items = DB::select("
			SELECT es.*, DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, al.appraisal_level_name, p.position_code, o.org_name, es.is_active
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
			WHERE 1=1
			AND jf.job_function_name = 'VSM'
			".$start_date."
			".$emp_snapshot_id."
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

	public function show($emp_snapshot_id)
	{
		try {
			$item = EmployeeSnapshot::findOrFail($emp_snapshot_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'EmployeeSnapshot not found.']);
		}

		$items = DB::select("
			SELECT DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, es.emp_id, es.emp_code, es.emp_first_name, es.emp_last_name, es.email, es.chief_emp_code, es.distributor_code, es.distributor_name, es.region, al.appraisal_level_name, p.position_code, p.position_name, o.org_code, o.org_name, es.is_active, es.emp_snapshot_id
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			WHERE es.emp_snapshot_id = {$emp_snapshot_id}
		");
		return response()->json($items[0]);
	}

	public function update($emp_snapshot_id, Request $request)
	{
		try {
			$item = EmployeeSnapshot::findOrFail($emp_snapshot_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'EmployeeSnapshot Level not found.']);
		}

		$validator = Validator::make($request->all(), [
			'emp_first_name' => 'required|max:100',
			'emp_last_name' => 'required|max:100'
		],[
			'emp_first_name.required' => 'The Name VSM field is required.',
			'emp_last_name.required' => 'The Last Name AVSM field is required.'
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
}