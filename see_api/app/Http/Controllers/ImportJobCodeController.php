<?php

namespace App\Http\Controllers;

use App\EmpLevel;
use App\JobCode;
use App\Position;
use App\Org;
use App\User;

use Auth;
use Crypt;
use DB;
use File;
use Validator;
use Excel;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ImportJobCodeController extends Controller
{
    public function __construct()
	{

	   $this->middleware('jwt.auth');
    }
    
    public function index(Request $request)
    {
		$qinput = array();
		$query = "
        SELECT
            job_code,
            knowledge_point,
            capability_point,
            total_point,
            baht_per_point 
        FROM
            job_code
        WHERE 1=1
		";
		empty($request->job_code) ?: ($query .= " And job_code = ? " AND $qinput[] = $request->job_code);

		$qfooter = " ORDER BY job_code ";

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

    public function export(Request $request)
	{
		$qinput = array();
		$query = "
		SELECT
            job_code,
            knowledge_point,
            capability_point,
            baht_per_point 
        FROM
             job_code 
        ORDER BY
             job_code
        ";
        
		$items = DB::select($query);
		
		$filename = "import_job_code";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('Job Code', 'Knowledge Point', 'Capability Point', 'Baht Per Point'));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->job_code,
						$i->knowledge_point,
						$i->capability_point,
						$i->baht_per_point, 
					));
				}
			});
		})->export('xlsx');
    }

    public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '1024M');

		$errors = array();
		$newEmp = array();
		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();
			foreach ($items as $i) {
				$validator = Validator::make($i->toArray(), [
					'job_code' => 'required|max:10',
					'knowledge_point' => 'numeric|required|between:0,99999.99',
					'capability_point' => 'numeric|required|between:0,99999.99',
					'baht_per_point' => 'numeric|required|between:0,99999.99'
				]);

				if ($validator->fails()) {
					$errors[] = ['job_code' => $i->job_code, 'errors' => $validator->errors()];
				} else {
					if(($i->knowledge_point + $i->capability_point) > 99999.99){
						$errors[] = ['job_code' => $i->job_code, 'errors' => ['total_point' => ['The total point (knowledge + capability) must be between 0 and 99999.99.']]];
					} else {
						$job = JobCode::where('job_code', $i->job_code)->first();
						if (empty($job)) {
							$job = new JobCode;
							$job->job_code = $i->job_code;
							$job->knowledge_point = $i->knowledge_point;
							$job->capability_point = $i->capability_point;
							$job->total_point = ($i->knowledge_point + $i->capability_point);
							$job->baht_per_point = $i->baht_per_point;
							$job->created_by = Auth::id();
							$job->updated_by = Auth::id();
							try {
								$job->save();
								// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม Job Code 
								$newEmp[] = ["job_code"=> $i->job_code];
							} catch (Exception $e) {
								$errors[] = ['job_code' => $i->job_code, 'errors' => substr($e,0,254)];
							}
						} else {    
							try {
								$user = Auth::id();
								$job = DB::table('job_code')
									->where('job_code', $i->job_code)
									->update([
										'knowledge_point' => $i->knowledge_point,
										'capability_point' => $i->capability_point,
										'total_point' => ($i->knowledge_point + $i->capability_point),
										'baht_per_point' => $i->baht_per_point,
										'updated_by' => Auth::id(),
										'updated_dttm' => date("Y-m-d H:i:s")
									]);
							} catch (Exception $e) {
								$errors[] = ['job_code' => $i->job_code, 'errors' => substr($e,0,254)];
							}
						}
					}
					
				}
			}
		}
		return response()->json(['status' => 200, 'errors' => $errors, "emp"=>$newEmp]);
    }

    public function destroy($job_code)
	{
		try {
			$item = JobCode::findOrFail($job_code);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Code not found.']);
		}

		try {
			$item->delete();

		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this job code is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200, "job_code"=>$job_code]);
    }
    

    public function update(Request $request, $job_code)
	{
		try {
			$item = JobCode::findOrFail($job_code);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Code not found.']);
		}

        $validator = Validator::make($request->all(), [
			'knowledge_point' => 'numeric|required|between:0,99999.99',
			'capability_point' => 'numeric|required|between:0,99999.99',
			'baht_per_point' => 'numeric|required|between:0,99999.99'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 400, 'data' => $validator->errors()]);
        } else {
			if(($request->knowledge_point + $request->capability_point) > 99999.99){
				return response()->json(['status' => 400, 'data' => ['total_point' => ['The total point (knowledge + capability) must be between 0 and 99999.99.']]]);
			} 

            $item->knowledge_point = $request->knowledge_point;
            $item->capability_point = $request->capability_point;
            $item->total_point = $request->total_point;
            $item->baht_per_point = $request->baht_per_point;
			$item->updated_by = Auth::id();
			$item->save();
		}
		return response()->json(['status' => 200, 'data' => $item]);
	}

}
