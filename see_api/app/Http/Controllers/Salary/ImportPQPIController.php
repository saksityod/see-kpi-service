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
			100 code,
			er.new_pqpi_amount
			from emp_result er
			INNER JOIN appraisal_form af on er.appraisal_form_id = af.appraisal_form_id
			INNER JOIN employee e on er.emp_id = e.emp_id
			WHERE 1 = 1
			and af.is_raise = 1	
		";
		empty($request->appraisal_form_id) ?: ($query .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);
		empty($request->period_id) ?: ($query .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
		$qfooter = " Order by e.emp_code"; 
//			'' effective_date, '' formula, and er.appraisal_form_id = ""
// 			'' expired_date        
		$items = DB::select($query . $qfooter, $qinput);

		//return response()->json(['status' => 200 , 'data' => $items]);


		$filename = "import_pqpi";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('EMPLOYEEID', 'CDOE', 'FORMULA','AMOUNT', 'EFF_DATE', 'EXP_DATE'));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->emp_code,
						$i->code,
						"",
						base64_decode($i->new_pqpi_amount),
						//$i->new_pqpi_amount,
						$request->effective_date,
						$request->expired_date, 
					));
				}
			});
		})->export('csv');
    }

}
