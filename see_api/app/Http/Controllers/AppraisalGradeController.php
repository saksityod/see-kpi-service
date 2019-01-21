<?php

namespace App\Http\Controllers;

use App\AppraisalGrade;
use App\SystemConfiguration;
use App\AppraisalStructure;
use App\AppraisalForm;

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

class AppraisalGradeController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

    public function al_list()
    {
		$items = DB::select("
			select level_id, appraisal_level_name
			from appraisal_level
			where is_active = 1
			and is_individual = 1
			and is_hr = 0
			order by appraisal_level_name
		");
		return response()->json($items);
	}


	public function struc_list()
	{
		$objStruc = DB::select("
			SELECT '0' structure_id, '-' structure_name
			UNION ALL
			SELECT structure_id, structure_name
			FROM appraisal_structure
			WHERE is_no_raise_value = 1
		");
		return response()->json($objStruc);
	}


	public function form_list()
    {
		$items = AppraisalForm::select('appraisal_form_id', 'appraisal_form_name')->get();
		return response()->json($items);
	}


	public function index(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$qinput = array();

		if ($config->raise_type == 1) {
			$query = "
			SELECT a.grade_id, a.appraisal_level_id, b.appraisal_level_name, a.grade,
				a.begin_score, a.end_score, ifnull(a.salary_raise_amount, '') salary_raise_amount,
				a.is_active, a.appraisal_form_id, f.appraisal_form_name
			FROM appraisal_grade a
			LEFT OUTER JOIN appraisal_level b ON a.appraisal_level_id = b.level_id
			LEFT OUTER JOIN appraisal_form f ON f.appraisal_form_id = a.appraisal_form_id
			WHERE 1=1
			";
		} else if($config->raise_type == 2) {
			$query = "
			SELECT a.grade_id, a.appraisal_level_id, b.appraisal_level_name, a.grade,
				a.begin_score, a.end_score, ifnull(a.salary_raise_percent, '') salary_raise_amount,
				a.is_active, a.appraisal_form_id, f.appraisal_form_name
			FROM appraisal_grade a
			LEFT OUTER JOIN appraisal_level b on a.appraisal_level_id = b.level_id
			LEFT OUTER JOIN appraisal_form f ON f.appraisal_form_id = a.appraisal_form_id
			WHERE 1=1
			";
		} else if($config->raise_type == 3) {
			$query = "
				SELECT a.grade_id, a.appraisal_level_id, b.appraisal_level_name, a.grade,
					a.begin_score, a.end_score, ifnull(a.salary_raise_step, '') salary_raise_amount,
					a.is_active, a.appraisal_form_id, f.appraisal_form_name
				FROM appraisal_grade a
				LEFT OUTER JOIN appraisal_level b ON a.appraisal_level_id = b.level_id
				LEFT OUTER JOIN appraisal_form f ON f.appraisal_form_id = a.appraisal_form_id
				WHERE 1=1
			";
		}

		empty($request->appraisal_form_id) ?: ($query .= " AND a.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);
		empty($request->appraisal_level_id) ?: ($query .= " AND a.appraisal_level_id = ? " AND $qinput[] = $request->appraisal_level_id);

		$qfooter = " ORDER BY a.appraisal_form_id, a.appraisal_level_id, a.begin_score";

		$items = DB::select($query . $qfooter, $qinput);


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


	public function store(Request $request)
	{
		$errors = array();
		$respData = collect();

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		// check level if not null
		if(count($request->appraisal_level_id) == 0){
			return response()->json(['status' => 404, 'data' => 'Please select at least one Appraisal Level.']);
		}

		// Perform one level at a timeใ
		foreach ($request->appraisal_level_id as $key => $vel) {
			$validator = Validator::make($request->all(), [
				'appraisal_form_id' => 'required|integer',
				'grade' => 'required|max:100|unique:appraisal_grade,grade,null,appraisal_level_id,appraisal_level_id,' . $vel,
				'begin_score' => 'required|numeric',
				'end_score' => 'required|numeric',
				'salary_raise_amount' => 'required|numeric',
				// 'is_active' => 'required|boolean',
			]);

			$range_check = DB::select("
				select grade, begin_score, end_score
				from appraisal_grade
				where appraisal_level_id = ?
				and (? between begin_score and end_score
				or ? between end_score and begin_score
				or ? between begin_score and end_score
				or ? between end_score and begin_score)
			", array($vel, $request->begin_score, $request->begin_score, $request->end_score, $request->end_score));

			if ($validator->fails()) {
				$errors = $validator->errors()->toArray();
				if (!empty($range_check)) {
					$errors['overlap'] = "The begin score and end score is overlapped with another grade.";//$range_check;
				}
				return response()->json(['status' => 400, 'data' => $errors]);
				
			} else {
				if (!empty($range_check)) {
					$errors['overlap'] = "The begin score and end score is overlapped with another grade.";//$range_check;
					return response()->json(['status' => 400, 'data' => $errors]);
				}

				$item = new AppraisalGrade;
				$item->fill($request->except(['salary_raise_amount', 'structure_id', 'appraisal_level_id', 'is_active']));

				$item->appraisal_level_id = $vel;
				$item->is_active = ($request->is_active == 'on') ? true : false;
				if ($config->raise_type == 1) {
					$item->salary_raise_amount = $request->salary_raise_amount;
					$item->salary_raise_percent = null;
					$item->salary_raise_step = null;
					$item->structure_id = null;
				} elseif ($config->raise_type == 2) {
					$item->salary_raise_amount = null;
					$item->salary_raise_percent = $request->salary_raise_amount;
					$item->salary_raise_step = null;
					$item->structure_id = null;
				}elseif ($config->raise_type == 3) {
					$item->salary_raise_amount = null;
					$item->salary_raise_percent = null;
					$item->salary_raise_step = $request->salary_raise_amount;
					$item->structure_id = ($request->structure_id==0)?null:$request->structure_id;
				}
	
				$item->created_by = Auth::id();
				$item->updated_by = Auth::id();
				$item->save();

				$respData->push($item);
			}
		}

		return response()->json(['status' => 200, 'data' => $respData]);
	}


	public function show($grade_id)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		try {
			$item = AppraisalGrade::findOrFail($grade_id);
			if($config->raise_type==2) {
				$item->salary_raise_amount = $item->salary_raise_percent;
			} elseif($config->raise_type==3) {
				$item->salary_raise_amount = $item->salary_raise_step;
				$item->structure_id = ($item->structure_id === null) ? 0 : $item->structure_id;
			}
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Grade not found.']);
		}
		return response()->json($item);
	}


	public function update(Request $request, $grade_id)
	{ 
		try {
			$item = AppraisalGrade::findOrFail($grade_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Grade not found.']);
		}

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		// check level if not null
		if(count($request->appraisal_level_id) == 0){
			return response()->json(['status' => 404, 'data' => 'Please select at least one Appraisal Level.']);
		}

		$errors = array();
		$validator = Validator::make($request->all(), [
			'appraisal_form_id' => 'required|integer',
			// 'appraisal_level_id' => 'required|integer',
			'grade' => 'required|max:100|unique:appraisal_grade,grade,' . $grade_id . ',grade_id,appraisal_level_id,' . $request->appraisal_level_id[0],
			'begin_score' => 'required|numeric',
			'end_score' => 'required|numeric',
			'salary_raise_amount' => 'required|numeric',
			// 'is_active' => 'required|boolean',
		]);

		$range_check = DB::select("
			select grade, begin_score, end_score
			from appraisal_grade
			where appraisal_level_id = ?
			and (? between begin_score and end_score
			or ? between end_score and begin_score
			or ? between begin_score and end_score
			or ? between end_score and begin_score)
			and grade <> ?
		", array($request->appraisal_level_id[0], $request->begin_score, $request->begin_score, $request->end_score, $request->end_score, $item->grade));

		if ($validator->fails()) {
			$errors = $validator->errors()->toArray();
			if (!empty($range_check)) {
				$errors['overlap'] = "The begin score and end score is overlapped with another grade.";//$range_check;
			}
			return response()->json(['status' => 400, 'data' => $errors]);
		} else {
			if (!empty($range_check)) {
				$errors['overlap'] = "The begin score and end score is overlapped with another grade.";//$range_check;
				return response()->json(['status' => 400, 'data' => $errors]);
			}
			$item->fill($request->except(['salary_raise_amount', 'structure_id', 'appraisal_level_id', 'is_active']));
			$item->appraisal_level_id = $request->appraisal_level_id[0];
			$item->is_active = ($request->is_active == 'on') ? true : false;
			if ($config->raise_type == 1) {
				$item->salary_raise_amount = $request->salary_raise_amount;
				$item->salary_raise_percent = null;
				$item->salary_raise_step = null;
				$item->structure_id = null;
			} elseif ($config->raise_type == 2) {
				$item->salary_raise_amount = null;
				$item->salary_raise_percent = $request->salary_raise_amount;
				$item->salary_raise_step = null;
				$item->structure_id = null;
			}elseif ($config->raise_type == 3) {
				$item->salary_raise_amount = null;
				$item->salary_raise_percent = null;
				$item->salary_raise_step = $request->salary_raise_amount;
				$item->structure_id = ($request->structure_id==0)?null:$request->structure_id;
			}
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);
	}


	public function destroy($grade_id)
	{
		try {
			$item = AppraisalGrade::findOrFail($grade_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Grade not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Appraisal Grade is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}
		return response()->json(['status' => 200]);
	}

}
