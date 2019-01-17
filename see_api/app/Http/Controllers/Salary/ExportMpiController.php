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

class ExportMpiController extends Controller
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
			CASE WHEN ISNULL(ej.adjust_amount) THEN er.mpi_amount ELSE ej.adjust_amount END as amount
			FROM emp_result er
				INNER JOIN appraisal_form af on er.appraisal_form_id = af.appraisal_form_id
				INNER JOIN employee e on er.emp_id = e.emp_id
				LEFT  JOIN emp_result_judgement ej on ej.emp_result_id = er.emp_result_id				
			WHERE 1 = 1
			and af.is_mpi = 1	
		";
		empty($request->appraisal_form_id) ?: ($query .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);
		empty($request->period_id) ? ($query .= " and er.period_id = '' ")  : ($query .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
		$query .= " and ej.created_dttm = (SELECT max(erj.created_dttm) FROM emp_result_judgement erj WHERE erj.emp_result_id = er.emp_result_id ) ";
		$qfooter = " Order by e.emp_code"; 
      
		$items = DB::select($query . $qfooter, $qinput);

		$filename = "export_mpi";  
		$x = Excel::create($filename, function($excel) use($items, $filename, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {
				$sheet->appendRow(array('EMPLOYEEID', 'CDOE', 'FORMULA','AMOUNT', 'START_DATE', 'END_DATE'));

				foreach ($items as $i) {						
					$sheet->appendRow(array(
						$i->emp_code,
						100,
						$request->formula,
						empty($i->amount) ? "" : number_format((float)(base64_decode($i->amount)), 2, '.', ''),
						$request->start_date,
						$request->end_date, 
					));
				}
			});
		})->export('csv');
    }

}
