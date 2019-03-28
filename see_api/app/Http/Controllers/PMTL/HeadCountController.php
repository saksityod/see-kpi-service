<?php

namespace App\Http\Controllers\PMTL;
use App\Http\Controllers\PMTL\QuestionaireDataController;

use App\HeadCount;
use App\JobFunction;
use App\Position;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Exception;
use DateTime;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class HeadCountController extends Controller
{
	protected $qdc_service;
	public function __construct(QuestionaireDataController $qdc_service)
	{

	   $this->middleware('jwt.auth');
	   $this->qdc_service = $qdc_service;
	}

	function format_date($date) {
        if(empty($date)) {
            return "";
        } else {
            $date = strtr($date, '/', '-');
            $date_formated = date('Y-m-d', strtotime($date));
        }

        return $date_formated;
	}
	
	public function auto(Request $request)
	{
	
		$items = DB::select("
			select DISTINCT p.position_id, CONCAT(p.position_name, ' ', '(', p.position_code,')') position_name, p.position_code
			from head_count hc
			INNER JOIN position p on hc.position_id = p.position_id
			where  (
				p.position_name like '%{$request->q}%'
				or p.position_code like '%{$request->q}%'
			)
			order by p.position_name asc
		");
		return response()->json($items);	
	}
	public function jf_list()
	{
		$items = DB::select("
			SELECT DISTINCT j.job_function_id,j.job_function_name
			FROM head_count hc
			INNER JOIN job_function j on hc.job_function_id = j.job_function_id
			ORDER BY j.job_function_id
		");
		return response()->json($items);
	}
	public function index(Request $request)
	{
		$request->start_date = $this->format_date($request->start_date);
        $request->end_date = $this->format_date($request->end_date);
		$between_date = $this->between_date_search($request->start_date, $request->end_date);
		
		empty($request->job_function_id) ? $job_function = "" : $job_function = " and hc.job_function_id = " . $request->job_function_id . " ";

		if(empty($request->position_name)) {
			$position = "";
		} else {
			$position = $this->qdc_service->concat_emp_first_last_code($request->position_name);
		}

		$items = DB::select("
			SELECT hc.head_count_id,hc.valid_date,hc.position_id,p.position_code,p.position_name,hc.job_function_id,j.job_function_name,hc.head_count 
			FROM head_count hc
			INNER JOIN position p on hc.position_id = p.position_id
			INNER JOIN job_function j on hc.job_function_id = j.job_function_id
			WHERE 1 = 1
			".$between_date.$job_function."
			and (
				p.position_name like '%{$position}%'
				or p.position_code like '%{$position}%'
			)
			order by hc.valid_date,p.position_code,job_function_id
			
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
	
	public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '5012M');
		$errors = array();
		
		

		

		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();
			//return response()->json(['status' => 200, 'errors' => '' ,'as'=>$items]);
			foreach ($items as $i) {
				
				$validator = Validator::make($i->toArray(), [
					'valid_date' => 'required|date|date_format:d.m.Y',
					'position_name' => 'required|max:100',
					'job_function_name' => 'required|max:11',
					'head_count' => 'required|integer'
				]);
				
				if ($validator->fails()) {
					$errors[] = ['position_name' => $i->position_name, 'errors' => $validator->errors()];
					/*
					$checkPosition = collect($errors)->where('position_name', $i->position_name);
					
					if(!empty($checkPosition)){
						
						$index;
						foreach($checkPosition as $key => $checkPosition){
							
							$index = $key;
						}
						
						$errors[$index]['errors']= collect($errors[$index]['errors'])->merge($validator->errors());
						
					}else{
						$errors[] = ['position_name' => $i->position_name, 'errors' => $validator->errors()];
					}*/
					
				} else {
					$errors_validator = array();
					$i->valid_date = $this->qdc_service->format_date($i->valid_date);
					$position = DB::table('position')->where('position_code',$this->qdc_service->trim_text($i->position_name))->first();
					$job_function = DB::table('job_function')->where('job_function_name',$this->qdc_service->trim_text($i->job_function_name))->first();
					
					if(empty($position)) {
						if(empty($job_function)) {
							$errors[] = ['position_name' => $i->position_name, 'errors' => ['Position ID' => ['Position ID not found.'],'Job Function ID' => ['Job Function ID not found.']]];
							$errors_validator[]="error";

						} else {
							$errors[] = ['position_name' => $i->position_name, 'errors' => ['Position ID' => ['Position ID not found.']]];
							$errors_validator[]="error";
						}
						
					} else {
						$position_id = $position->position_id;
						if(empty($job_function)) {
							$errors[] = ['position_name' => $i->position_name, 'errors' => ['Job Function ID' => ['Job Function ID not found.']]];
							$errors_validator[]="error";
						} else {
							$job_function_id = $job_function->job_function_id;
						}
					}
	
					if (empty($errors_validator)) {
						$headcount = HeadCount::where('valid_date', $i->valid_date)
							->where('position_id', $position_id)
							->where('job_function_id', $job_function_id)->first();
						//return response()->json(['status' => 200, 'errors' => $errors ,'data'=>$headcount]);
						if (empty($headcount)) {
							$headcount = new HeadCount;	
							$headcount->valid_date = $i->valid_date;
							$headcount->position_id = $position_id;
							$headcount->job_function_id = $job_function_id;
							$headcount->head_count = $i->head_count;
							$headcount->created_by = Auth::id();
							$headcount->updated_by = Auth::id();
							try {
								$headcount->save();
							} catch (Exception $e) {
								$errors[] = ['position_name' => $i->position_code, 'errors' => substr($e,0,254)];
							}
						}
						else{
							
							$headcount->head_count = $i->head_count;
							$headcount->updated_by = Auth::id();
							try {
								$headcount->save();
							} catch (Exception $e) {
								$errors[] = ['position_name' => $i->position_code, 'errors' => substr($e,0,254)];
							}
						}
						
						
					}

				}					
			}
		}
		//$ckPosition = collect($errors)->unique();
		
		return response()->json(['status' => 200, 'errors' => collect($errors)->unique(),'test'=>$errors]);
	}	
	
	public function store(Request $request)
	{
	
		$validator = Validator::make($request->all(), [
			'position_code' => 'required|unique:position',
			'position_name' => 'required|max:255',
			'is_active' => 'required|integer',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item = new Position;
			$item->fill($request->all());
			$item->created_by = Auth::id();
			$item->updated_by = Auth::id();
			$item->save();
		}
	
		return response()->json(['status' => 200, 'data' => $item]);	
	}
	
	public function show($position_id)
	{
		try {
			$item = Position::findOrFail($position_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Position not found.']);
		}
		return response()->json($item);
	}
	
	public function update(Request $request, $position_id)
	{
		try {
			$item = Position::findOrFail($position_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Position not found.']);
		}
		
		$validator = Validator::make($request->all(), [
			'position_code' => 'required|unique:position,position_name,' . $position_id . ',position_id',
			'position_name' => 'required|max:255',
			'is_active' => 'required|integer',
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
	
	public function destroy($position_id)
	{
		try {
			$item = Position::findOrFail($position_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Position not found.']);
		}	

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Position is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}
		
		return response()->json(['status' => 200]);
		
	}	

	function between_date_search($start_date, $end_date) {
        if(empty($start_date) && empty($end_date)) {
            $between_date = "";
        } else if(empty($start_date)) {
            $between_date = "AND hc.valid_date BETWEEN '' AND '{$end_date}' ";
        } else if(empty($end_date)) {
            $between_date = "AND hc.valid_date >= '{$start_date}' ";
        } else {
            $between_date = "AND hc.valid_date BETWEEN '{$start_date}' AND '{$end_date}' ";
        }
        return $between_date;
    }
}
