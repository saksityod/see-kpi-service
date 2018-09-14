<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\SalaryStructure;
use App\AppraisalLevel;
use Auth;
use DB;
use Excel;
use Exception;
use File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Validator;

class SalaryStructureController extends Controller {
	
	public function __construct() {
		$this->middleware ( 'jwt.auth' );
	}
	
	
	public function all_list_year() {
		$objYear = SalaryStructure::select('appraisal_year')
			->distinct()
			->orderBy('appraisal_year', 'desc')
			->get();
		return response()->json(["status"=>200, "data"=>$objYear]);
	}
	
	
	public function all_list_level() { 
		$objLevel = DB::select("
			SELECT al.level_id, al.appraisal_level_name
			FROM appraisal_level al 
			WHERE EXISTS (
				SELECT 1 FROM salary_structure 
				WHERE level_id = al.level_id
			)
			AND al.is_individual = 1
			ORDER BY al.level_id
		");
		return response()->json(["status"=>200, "data"=>$objLevel]);
	}
	
	
	public function index(Request $requst) {
		$yearQryStr = empty($requst->year) ? "" :"AND ss.appraisal_year = '{$requst->year}'";
		$levelQryStr = empty($requst->level) ? "" : "AND ss.level_id = '{$requst->level}'";
		$stepQryStr = empty($requst->step_from) && empty($requst->step_to) ? "" : "AND ss.step BETWEEN '{$requst->step_from}' and '{$requst->step_to}'";

		$objSalaryStruc = DB::select("
			SELECT ss.appraisal_year, ss.level_id, ss.step, 
				ss.s_amount, ss.minimum_wage_amount, al.appraisal_level_name
			FROM salary_structure ss
			LEFT OUTER JOIN appraisal_level al ON al.level_id = ss.level_id
			WHERE 1 = 1 
			".$yearQryStr."
			".$levelQryStr."
			".$stepQryStr."
		");
		
		return response()->json(["status"=>200, "data"=>$objSalaryStruc]);
	}
	
	
	public function show(Request $requst) {
		try {
			$item = SalaryStructure::where ( 'appraisal_year', $requst->appraisal_year )
				->where ( 'level_id', $requst->level_id )
				->where ( 'step', $requst->step )
				->firstOrFail();
		} catch ( ModelNotFoundException $e ) {
			return response ()->json ( [ 
					'status' => 404,
					'data' => 'Salary Structure not found.' 
			] );
		}
		return response ()->json ( $item );
	}
	
	
	public function update(Request $request) { 
		try {
			$item = SalaryStructure::where ( 'appraisal_year', $request->appraisal_year )
				->where ( 'level_id', $request->level_id )
				->where ( 'step', $request->step )
				->firstOrFail();
		} catch ( ModelNotFoundException $e ) {
			return response ()->json ( [ 
					'status' => 404,
					'data' => 'Salary Structure not found.' 
			] );
		}
		
		$validator = Validator::make ( $request->all (), [ 
				'appraisal_year' => 'required|integer',
				'level_id' => 'required|integer',
				'step' => 'required|between:0,99.99',
				's_amount' => 'required|numeric', 
				'minimum_wage_amount' => 'required|numeric' 
		] );
		
		if ($validator->fails()) {
			return response()->json ( [ 
				'status' => 400,
				'data' => $validator->errors() 
			] );
		} else {
			SalaryStructure::where ( 'appraisal_year', $request->appraisal_year )
				->where ( 'level_id', $request->level_id )
				->where ( 'step', $request->step )
				->update(array('s_amount' => $request->s_amount,
								'minimum_wage_amount' => $request->minimum_wage_amount));
				//->update(array('minimum_wage_amount' => $request->minimum_wage_amount));
			
			//$item->s_amount = $request->s_amount;
			//$item->save();
		}
		
		return response()->json([ 
			'status' => 200,
			'data' => $item 
		]);
	}
	
	
	public function import(Request $request) {
		// ini_set('max_execution_time', 180); // 3 Minutes
		set_time_limit(0);
		ini_set('memory_limit', '1024M');
		$errors = array ();
		foreach ( $request->file () as $f ) {
			$items = Excel::load ( $f, function ($reader) {
			} )->get ();
			foreach ( $items as $i ) {
				$validator = Validator::make ( $i->toArray(), [ 
						'year' => 'required|integer',
						'level_id' => 'required|integer',
						'level_name' => 'required|max:255',
						'step' => 'required|between:0,99.99',
						'salary' => 'required|integer', 
						'minimum_salary' => 'integer'
				] );
				
				if ($validator->fails()) {
					$errors [] = ['step'=>$i->step, 'errors'=>$validator->errors ()];
				} else { 
					$getExistData = SalaryStructure::where('appraisal_year', $i->year)
						->where('level_id', $i->level_id)
						->where('step', $i->step)
						->get();

					// Set minimum wage amount //
					if (empty($i->minimum_salary)) {
						$minWageAmount = 0 ;
					}else {
						$minWageAmount = $i->minimum_salary;
					}

					// Insert / Update //
					if (count($getExistData) == 0) { // Insert //
						$salaryStructure = new SalaryStructure;
						$salaryStructure->appraisal_year = $i->year;
						$salaryStructure->level_id = $i->level_id;
						$salaryStructure->step = $i->step;
						$salaryStructure->s_amount = $i->salary;
						$salaryStructure->minimum_wage_amount = $minWageAmount;
						$salaryStructure->created_by = Auth::id();
						$salaryStructure->updated_by = Auth::id();

						try {
							$salaryStructure->save();
						} catch ( Exception $e ) {
							$errors [] = [
								'step'=>'Update, Year:'.$i->year
									.', Level:'.$i->level_name
									.', Step:'.$i->step,
								'errors' => $e
							];
						}
					} else { // Update //
						try {
							DB::table('salary_structure')
							->where('appraisal_year', $i->year)
							->where('level_id', $i->level_id)
							->where('step', $i->step)
							->update([
								's_amount' => $i->salary,
								'minimum_wage_amount' => $minWageAmount,
								'updated_by' => Auth::id(),
								'updated_dttm' => date('Y-m-d H:i:s')]);
						} catch ( Exception $e ) {
							$errors [] = [
								'step'=>'Update, Year:'.$i->year
									.', Level:'.$i->level_name
									.', Step:'.$i->step, 
								'errors' => $e
							];
						}
					}
				}
			}
		}
		return response ()->json(['status' => 200,'errors' => $errors]);
	}
	
	
	public function destroy(Request $requst) {
		try {
			$item = SalaryStructure::where ( 'appraisal_year', $requst->appraisal_year )
				->where ( 'level_id', $requst->level_id )
				->where ( 'step', $requst->step )
				->firstOrFail();
		} catch ( ModelNotFoundException $e ) {
			return response ()->json ( [ 
					'status' => 404,
					'data' => 'Salary Structure not found.' 
			] );
		}
		
		try {
			SalaryStructure::where ( 'appraisal_year', $requst->appraisal_year )
				->where ( 'level_id', $requst->level_id )
				->where ( 'step', $requst->step )
				->delete();
		} catch ( Exception $e ) {
			return response ()->json ( [ 
				'status' => 400,
				'data' => 'Cannot delete because this Salary Structure is in use. ('.$e->getMessage().')' 
			]);
		}
		
		return response ()->json ( [ 
				'status' => 200 
		] );
	}
}
