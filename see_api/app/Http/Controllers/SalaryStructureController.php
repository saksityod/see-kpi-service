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
		//$this->middleware ( 'jwt.auth' );
	}
	
	
	public function all_list_year() {
		$objYear = SalaryStructure::select('appraisal_year')
			->distinct()
			->orderBy('appraisal_year', 'desc')
			->get();
		return response()->json(["status"=>200, "data"=>$objYear]);
	}
	
	
	public function all_list_level() { 
		$objLevel = AppraisalLevel::select("level_id", "appraisal_level_name")
			->where("is_individual", "1") 
			->orderBy('level_id', 'asc')
			->get();
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
		$errors = array ();
		foreach ( $request->file () as $f ) {
			$items = Excel::load ( $f, function ($reader) {
			} )->get ();
			foreach ( $items as $i ) { return response()->json($i);
				
				$validator = Validator::make ( $i->toArray (), [ 
						'year' => 'required|integer',
						'level_id' => 'required|integer',
						'level_name' => 'required|max:255',
						'step' => 'required|between:0,99.99',
						'salary' => 'required|integer', 
						'minimum_salary' => 'integer'
				] );
				
				if ($validator->fails ()) {
					$errors [] = [ 
							'step' => $i->step,
							'errors' => $validator->errors () 
					];
				} else {
					$org = DB::select ( "
						select appraisal_year
						from salary_structure
						where appraisal_year = ?
						and level_id = ?
						and step = ?
					", array (
							$i->year,
							$i->level_id,
							$i->step 
					) );
					if (empty ( $org )) {
						$org = new SalaryStructure;
						$org->appraisal_year = $i->year;
						$org->level_id = $i->level_id;
						$org->step = $i->step;
						$org->s_amount = $i->salary;						
						if (empty($i->minimum_salary)) {
							$org->minimum_wage_amount = 0 ; //$i->minimun_salary
						}else {
							$org->minimum_wage_amount = $i->minimum_salary;
						}
						try {
							$org->save ();
						} catch ( Exception $e ) {
							$errors [] = [ 
								'step' => $i->step,
								'errors' => $e
							];
						}
					} else {
						SalaryStructure::where ( 'appraisal_year', $i->year )->where ( 'level_id', $i->level_id )->where ( 'step', $i->step )->update ( [ 
								's_amount' => $i->salary, 
								'minimum_wage_amount' => $i->minimun_salary,
						] );
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
