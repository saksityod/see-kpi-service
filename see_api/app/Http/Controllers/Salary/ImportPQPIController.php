<?php

namespace App\Http\Controllers\Salary;

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
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ImportPQPIController extends Controller
{
    public function __construct()
	{

	   $this->middleware('jwt.auth');
    }
    

    public function export(Request $request)
	{
		$qinput = array();
		$query = "
		SELECT 
			e.emp_code,
			er.adjust_new_pqpi_amount
			from emp_result er
			INNER JOIN appraisal_form af on er.appraisal_form_id = af.appraisal_form_id
			INNER JOIN employee e on er.emp_id = e.emp_id
			WHERE 1 = 1
			and af.is_raise = 1	
			and af.is_active = 1
		";
		empty($request->appraisal_form_id) ?: ($query .= " and er.appraisal_form_id IN (".$request->appraisal_form_id.") ");
		empty($request->period_id) ? ($query .= " and er.period_id = '' ")  : ($query .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
		$qfooter = " Order by e.emp_code"; 
      
		$items = DB::select($query . $qfooter, $qinput);

		//return response()->json(['status' => 200 , 'data' => $items]);

		$filename = "exort_pqpi";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('EMPLOYEEID', 'CDOE', 'FORMULA','AMOUNT', 'START_DATE', 'END_DATE'));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->emp_code,
						100,
						133,
						empty($i->adjust_new_pqpi_amount) ? "" : number_format((float)(base64_decode($i->adjust_new_pqpi_amount)), 2, '.', ''),
						$request->effective_date,
						$request->expired_date, 
					));
				}
			});
		})->export('csv');
    }

}
