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

class ImportSalaryController extends Controller
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
			er.new_s_amount salary,
			er.s_amount old_salary
			from emp_result er
			INNER JOIN appraisal_form af on er.appraisal_form_id = af.appraisal_form_id
			INNER JOIN employee e on er.emp_id = e.emp_id
			WHERE 1 = 1
			and af.is_raise = 1	
		";
		empty($request->appraisal_form_id) ?: ($query .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);
		empty($request->period_id) ? ($query .= " and er.period_id = '' ") : ($query .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
		$qfooter = " Order by e.emp_code"; 

		$items = DB::select($query . $qfooter, $qinput);

		//return response()->json(['status' => 200 , 'data' => $items]);


		$filename = "import_salary";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('EMPLOYEEID', 'EFF_DATE', 'ADJ_DATE','ADJ_TYPE', 'ADJ_REASON', 'SALARY','OLD_SALARY'));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->emp_code,
						$request->effective_date,
						$request->adjust_date, 
						24,
						7,
						empty($i->salary) ? "" : number_format((float)(base64_decode($i->salary)), 2, '.', ''),
						empty($i->old_salary) ? "" : number_format((float)(base64_decode($i->old_salary)), 2, '.', ''),
					));
				}
			});
		})->export('csv');
    }

}
