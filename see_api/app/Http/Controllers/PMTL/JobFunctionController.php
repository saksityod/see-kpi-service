<?php

namespace App\Http\Controllers\PMTL;

use App\JobFunction;

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

class JobFunctionController extends Controller
{

	public function __construct()
	{

	   $this->middleware('jwt.auth');
	}
	
	public function index(Request $request)
	{
		empty($request->job_function_id) ? $job_function = "" : $job_function = " and job_function_id = " . $request->job_function_id . " ";
		$items = DB::select("
			SELECT * 
			FROM job_function
			where 1=1 " . $job_function . "
		    ORDER BY job_function_id
		");
		return response()->json($items);
	}

	public function al_list()
	{
		$items = DB::select("
			SELECT j.job_function_id,j.job_function_name
			FROM job_function AS j
		");
		return response()->json($items);
	}

	public function store(Request $request)
	{

		$validator = Validator::make($request->all(), [
			'job_function_name' => 'required|unique:job_function',
			'is_evaluated' => 'required|integer',
			'is_show_report' => 'required|integer',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item = new JobFunction;
			$item->fill($request->all());
			$item->is_show_report = $request->is_show_report;
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);
	}

	public function show(Request $request, $job_function_id)
	{
		try {
			$item = JobFunction::findOrFail($job_function_id);

		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Function not found.']);
		}
		return response()->json($item);
	}

	public function update(Request $request, $job_function_id)
	{
		try {
			$item = JobFunction::findOrFail($job_function_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Function not found.']);
		}

		$validator = Validator::make($request->all(), [
			'job_function_name' => 'required|max:255|unique:job_function,job_function_name,' . $job_function_id . ',job_function_id',
			'is_evaluated' => 'required|integer',
			'is_show_report' => 'required|integer',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item->fill($request->all());
			$item->is_show_report = $request->is_show_report;
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);

	}
	

	public function destroy($job_function_id)
	{
		try {
			$item = JobFunction::findOrFail($job_function_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Function not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Job Function is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}
}
