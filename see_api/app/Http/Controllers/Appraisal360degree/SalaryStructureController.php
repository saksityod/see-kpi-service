<?php

namespace App\Http\Controllers\Appraisal360degree;

use App\SalaryStructure;

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

class SalaryStructureController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	public function all_list_year() {
		$items = DB::select("
				SELECT appraisal_year
				FROM salary_structure
				where is_active = 1
			");
		return response()->json($items);
	}

	public function all_list_level() {
		$items = DB::select("
				SELECT level_id
				FROM salary_structure
				where is_active = 1
			");
		return response()->json($items);
	}

	public function index(Request $requst) {
		$year = empty($requst->appraisal_year) ? "" : "AND appraisal_year = '{$requst->appraisal_year}'";
		$level = empty($requst->level_id) ? "" : "AND level_id = '{$requst->level_id}' ";

		$items = DB::select("
				SELECT *
				FROM salary_structure
				where is_active = 1
				".$year."
				".$level."
			");
		return response()->json($items);
	}

	public function show(Request $requst) {
		try {
			$item = CompetencyCriteria::where('appraisal_year',$request->appraisal_year)->where('level_id', $request->level_id)->where('step', $request->step)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Salary Structure not found.']);
		}
		return response()->json($item);
	}

	public function update(Request $request) {
		try {
			$item = CompetencyCriteria::where('appraisal_year',$request->appraisal_year)->where('level_id', $request->level_id)->where('step', $request->step)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Salary Structure not found.']);
		}

		$validator = Validator::make($request->all(), [
			'appraisal_year' => 'required|integer',
			'level_id' => 'required|integer',
			'step' => 'required|between:0,99.99',
			's_amount' => 'required|integer'
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item->s_amount = $requst->s_amount;
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);

	}

	public function import(Request $request) {
		$errors = array();
		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();
			foreach ($items as $i) {

				$validator = Validator::make($i->toArray(), [
					'appraisal_year' => 'required|integer',
					'level_id' => 'required|integer',
					'step' => 'required|between:0,99.99',
					's_amount' => 'required|integer'
				]);

				if ($validator->fails()) {
					$errors[] = ['appraisal_year' => $i->appraisal_year, 'level_id' => $i->level_id, 'step' => $i->step, 'errors' => $validator->errors()];
				} else {
					$org = DB::select("
						select appraisal_year
						from salary_structure
						where appraisal_year = ?
						and level_id = ?
						and step = ?
					",array($i->appraisal_year, $i->level_id, $i->step));
					if (empty($org)) {
						$org = new SalaryStructure;
						$org->appraisal_year = $i->appraisal_year;
						$org->level_id = $i->level_id;
						$org->step = $i->step;
						$org->s_amount = $i->s_amount;
						$org->is_active = 1;
						try {
							$org->save();
						} catch (Exception $e) {
							$errors[] = ['appraisal_year' => $i->appraisal_year, 'level_id' => $i->level_id, 'step' => $i->step, 'errors' => substr($e,0,254)];
						}
					} else {
 						SalaryStructure::where('appraisal_year',$i->appraisal_year)->where('level_id',$i->level_id)->where('step',$i->step)->update(['s_amount' => $i->s_amount, 'is_active' => $i->is_active]);
					}
				}
			}
		}
		return response()->json(['status' => 200, 'errors' => $errors]);
	}

	public function destroy(Request $requst)
	{
		try {
			$item = CompetencyCriteria::where('appraisal_year',$request->appraisal_year)->where('level_id', $request->level_id)->where('step', $request->step)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Salary Structure not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Salary Structure is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}
}
