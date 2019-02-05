<?php

namespace App\Http\Controllers;

use App\Position;
use App\Employee;

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

class PositionController extends Controller
{

	public function __construct()
	{

	   $this->middleware('jwt.auth');
	}
	
	public function auto(Request $request)
	{
		$items = DB::select("
			select position_id, position_name, position_code
			from position
			where is_active = 1
			and position_name like ?
			order by position_name asc
		", array('%'.$request->q.'%'));
		return response()->json($items);	
	}
	
	public function index(Request $request)
	{		
		$items = DB::select("
			select position_id, position_name, position_code, job_code, is_active
			from position
			where position_name like ?
			order by position_code asc
		", array('%'.$request->position_name.'%'));
		return response()->json($items);
	}
	
	public function import(Request $request)
	{
		$errors = array();
		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();
			foreach ($items as $i) {
				
				$validator = Validator::make($i->toArray(), [
					'position_name' => 'required|max:255',
					'position_code' => 'required',
					'job_code' => 'max:10|exists:job_code,job_code'
				]);

				if ($validator->fails()) {
					$errors[] = ['position_name' => $i->position_name, 'errors' => $validator->errors()];
				} else {
					/*$position = DB::select("
						select position_id
						from position
						where position_code = ?
					",array($i->position_code));*/
					$position = position::where('position_code', $i->position_code)->first();
					if (empty($position)) {
						$position = new Position;	
						$position->position_name = $i->position_name;
						$position->position_code = $i->position_code;
						$position->job_code = $i->job_code;
						$position->is_active = 1;
						$position->created_by = Auth::id();
						$position->updated_by = Auth::id();
						try {
							$position->save();
						} catch (Exception $e) {
							$errors[] = ['position_name' => $i->position_code, 'errors' => ["insert_error"=>[substr($e,0,254)]]];
						}
					} else {
						$position = position::find($position->position_id);
						$position->position_name = $i->position_name;
						$position->position_code = $i->position_code;
						$position->job_code = $i->job_code;
						$position->is_active = 1;
						$position->updated_by = Auth::id();
						try {
							$position->save();
						} catch (Exception $e) {
							$errors[] = ['position_name' => $i->position_code, 'errors' => substr($e,0,254)];
						}

					}
				}					
			}
		}
		return response()->json(['status' => 200, 'errors' => $errors]);
	}	
	
	public function store(Request $request)
	{
	
		$validator = Validator::make($request->all(), [
			'position_code' => 'required|unique:position',
			'position_name' => 'required|max:255',
			'job_code' => 'required|max:10',
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
			'job_code' => 'required|max:10',
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

		// ตรวจสอบเพิ่มเติมในกรณีที่ Position ถูกใช้งานแล้วใน Employee (*ไม่ได้กำหนด Foreign Key)
		$posInEmpCnt = Employee::where('position_id', $position_id)->count();
		if($posInEmpCnt > 0){
			return response()->json(['status' => 400, 'data' => 'Cannot delete because this Position is in use Employee.']);
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

	public function export(Request $request)
	{
		$qinput = array();
		$query = "
			SELECT
				position_id, position_code , position_name , job_code
			FROM position
			WHERE is_active = 1
			AND position_name LIKE '%{$request->position_name}%'
			Order by position_code
		";
		$items = DB::select($query);
		
		$filename = "appraisal_position_template";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('Position Code', 'Position Name', 'Job Code' ));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->position_code,
						$i->position_name,
						$i->job_code
						
					));
				}
			});
		})->export('xlsx');
	}
}
