<?php

namespace App\Http\Controllers;

use App\EmpLevel;
use App\Employee;
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

class ImportEmployeeController extends Controller
{

	public function __construct()
	{

	   $this->middleware('jwt.auth');
	}

	public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '1024M');

		$errors = array();
		$newEmp = array();
		foreach ($request->file() as $f) {
			$items = Excel::selectSheets('import_employee_template')->load($f, function($reader){})->get();
			foreach ($items as $i) {

				$validator = Validator::make($i->toArray(), [
					'employee_code' => 'required|max:255',
					'employee_name' => 'required|max:255',
					'working_start_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'probation_end_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'acting_end_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'organization_code' => 'required',
					// 'position_code' => 'required',
					'chief_employee_code' => 'max:255',
					//'salary_amount' => 'numeric|digits_between:1,10',
					'email' => 'required|email|max:100',
					'employee_type' => 'max:50',
					'dotline_code' => 'max:255',
					'has_second_line' => 'max:255',
					'pqpi_amount' => 'max:100',
					'fix_other_amount' => 'max:100',
					'mpi_amount' => 'max:100',
					'pi_amount' => 'max:100',
					'var_other_amount' => 'max:100'
				]);

				$org = Org::where('org_code',$i->organization_code)->first();
				$position = Position::where('position_code',$i->position_code)->first();

				empty($org) ? $org_id = null : $org_id = $org->org_id;
				empty($position) ? $position_id = null : $position_id = $position->position_id;

				if ($validator->fails()) {
					$errors[] = ['employee_code' => $i->employee_code, 'errors' => $validator->errors()];
				} else {
					$emp = Employee::where('emp_code', $i->employee_code)->first();
					if (empty($emp)) {
						$emp = new Employee;
						$emp->emp_code = $i->employee_code;
						$emp->emp_name = $i->employee_name;
						$emp->working_start_date = $i->working_start_date_yyyy_mm_dd;
						$emp->probation_end_date = $i->probation_end_date_yyyy_mm_dd;
						$emp->acting_end_date = $i->acting_end_date_yyyy_mm_dd;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->chief_emp_code = $i->chief_employee_code;
						$emp->s_amount = base64_encode($i->salary_amount);
						$emp->email = $i->email;
						$emp->emp_type = $i->employee_type;
						$emp->dotline_code = $i->dotline_code;
						$emp->has_second_line = $i->has_second_line;
						$emp->is_active = 1;
						$emp->pqpi_amount = base64_encode($i->pqpi_amount);
						$emp->fix_other_amount = base64_encode($i->fix_other_amount);
						$emp->mpi_amount = base64_encode($i->mpi_amount);
						$emp->pi_amount = base64_encode($i->pi_amount);
						$emp->var_other_amount = base64_encode($i->var_other_amount);
						$emp->level_id = $i->level_id;
						$emp->created_by = Auth::id();
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
							// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay
							$newEmp[] = ["emp_code"=> $i->employee_code, "emp_name"=>$i->employee_name, "email"=>$i->email];
						} catch (Exception $e) {
							$errors[] = ['employee_code' => $i->employee_code, 'errors' => substr($e,0,254)];
						}
					} else {
						$emp->emp_name = $i->employee_name;
						$emp->working_start_date = $i->working_start_date_yyyy_mm_dd;
						$emp->probation_end_date = $i->probation_end_date_yyyy_mm_dd;
						$emp->acting_end_date = $i->acting_end_date_yyyy_mm_dd;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->chief_emp_code = $i->chief_employee_code;
						$emp->s_amount = base64_encode($i->salary_amount);
						$emp->email = $i->email;
						$emp->emp_type = $i->employee_type;
						$emp->dotline_code = $i->dotline_code;
						$emp->has_second_line = $i->has_second_line;
						$emp->is_active = 1;
						$emp->pqpi_amount = base64_encode($i->pqpi_amount);
						$emp->fix_other_amount = base64_encode($i->fix_other_amount);
						$emp->mpi_amount = base64_encode($i->mpi_amount);
						$emp->pi_amount = base64_encode($i->pi_amount);
						$emp->var_other_amount = base64_encode($i->var_other_amount);
						$emp->level_id = $i->level_id;
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
						} catch (Exception $e) {
							$errors[] = ['employee_code' => $i->employee_code, 'errors' => substr($e,0,254)];
						}
					}
				}
			}
		}

		// License Verification //
		try{
			$empAssign = config("session.license_assign");
			if((!empty($empAssign))&&$empAssign!=0){
				$mail = new MailController();
				$result = $mail->LicenseVerification();
			}
		} catch (Exception $e) {
		}

		return response()->json(['status' => 200, 'errors' => $errors, "emp"=>$newEmp]);
	}

	public function index(Request $request)
	{
		$qinput = array();
		$query = "
			select a.emp_id, a.emp_code, a.emp_name, c.org_name, d.appraisal_level_name, b.position_name, a.chief_emp_code, a.emp_type, a.dotline_code, a.has_second_line
			From employee a left outer join position b
			on a.position_id = b.position_id
			left outer join org c
			on a.org_id = c.org_id
			left outer join appraisal_level d
			on a.level_id = d.level_id
			Where 1=1
		";

		empty($request->org_id) ?: ($query .= " AND a.org_id = ? " AND $qinput[] = $request->org_id);
		empty($request->position_id) ?: ($query .= " And a.position_id = ? " AND $qinput[] = $request->position_id);
		empty($request->emp_code) ?: ($query .= " And a.emp_code = ? " AND $qinput[] = $request->emp_code);

		$qfooter = " Order by a.emp_code ";

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
			SELECT em.emp_code
			, em.emp_name
			, em.working_start_date
			, em.probation_end_date
			, em.acting_end_date
			, org.org_code
			, po.position_code
			, em.chief_emp_code
			, from_base64(em.s_amount) as s_amount
			, em.email
			, em.emp_type
			, em.dotline_code
			, em.has_second_line
			, from_base64(em.pqpi_amount) as pqpi_amount
			, from_base64(em.fix_other_amount) as fix_other_amount
			, from_base64(em.mpi_amount) as mpi_amount
			, from_base64(em.pi_amount) as pi_amount
			, from_base64(em.var_other_amount) as var_other_amount
			, em.level_id
			FROM employee em
			LEFT OUTER JOIN position po ON em.position_id = po.position_id
			LEFT OUTER JOIN org ON em.org_id = org.org_id
			LEFT OUTER JOIN appraisal_level le ON em.level_id = le.level_id
			WHERE 1 = 1
			AND em.is_active = 1
			AND le.is_hr != 1";

		empty($request->org_id) ?: ($query .= " AND org.org_id = ? " AND $qinput[] = $request->org_id);
		empty($request->position_id) ?: ($query .= " And po.position_id = ? " AND $qinput[] = $request->position_id);
		empty($request->emp_code) ?: ($query .= " And em.emp_code = ? " AND $qinput[] = $request->emp_code);
		$qfooter = " Order by em.emp_code ASC";

		$items = DB::select($query . $qfooter, $qinput);

		$level = DB::select("
				SELECT level_id
				, appraisal_level_name
				FROM appraisal_level
				WHERE is_active = 1
				AND is_individual = 1
				ORDER BY level_id ASC");

		$filename = "import_employee_template";
		$sheet_level = "level";
		$x = Excel::create($filename, function($excel) use($items, $level, $filename, $sheet_level, $request) {
			$excel->sheet($filename, function($sheet) use($items, $request) {

				$sheet->appendRow(array('Employee Code', 'Employee Name', 'Working Start Date (YYYY-MM-DD)', 'Probation End Date (YYYY-MM-DD)', 'Acting End Date (YYYY-MM-DD)', 'Organization Code', 'Position Code','Chief Employee Code', 'Salary Amount', 'Email', 'Employee Type', 'Dotline Code', 'Has Second Line', 'PQPI Amount', 'Fix Other Amount', 'MPI Amount', 'PI Amount', 'Var Other Amount', 'Level ID'));

				foreach ($items as $i) {
					$sheet->appendRow(array(
						$i->emp_code,
						$i->emp_name,
						$i->working_start_date,
						$i->probation_end_date,
						$i->acting_end_date,
						$i->org_code,
						$i->position_code,
						$i->chief_emp_code,
						"", // $i->s_amount,
						$i->email,
						$i->emp_type,
						$i->dotline_code,
						$i->has_second_line,
						$i->pqpi_amount,
						$i->fix_other_amount,
						$i->mpi_amount,
						$i->pi_amount,
						$i->var_other_amount,
						$i->level_id,
					));
				}
			});
			$excel->sheet($sheet_level, function($sheet) use($level, $request) {

				$sheet->appendRow(array('Level ID', 'Appraisal Level Name'));

				foreach ($level as $l) {
					$sheet->appendRow(array(
						$l->level_id,
						$l->appraisal_level_name,
					));
				}
			});
		})->export('xlsx');
	}

    public function role_list()
    {
		$items = DB::select("
			select appraisal_level_id, appraisal_level_name
			from appraisal_level
			where is_active = 1
			order by appraisal_level_name
		");
		return response()->json($items);
    }

	public function dep_list()
	{
		$items = DB::select("
			Select distinct department_code, department_name
			From employee
			Order by department_name
		");
		return response()->json($items);
	}

    public function sec_list(Request $request)
    {

		$qinput = array();
		$query = "
			Select distinct section_code, section_name
			From employee
			Where 1=1
		";

		$qfooter = " Order by section_name ";

		empty($request->department_code) ?: ($query .= " and department_code = ? " AND $qinput[] = $request->department_code);

		$items = DB::select($query.$qfooter,$qinput);
		return response()->json($items);
    }

	public function auto_position_name(Request $request)
	{
		$qinput = array();
		$query = "
			Select distinct position_code, position_name
			From employee
			Where position_name like ?
		";

		$qfooter = " Order by position_name limit 10";
		$qinput[] = '%'.$request->position_name.'%';
		empty($request->section_code) ?: ($query .= " and section_code = ? " AND $qinput[] = $request->section_code);
		empty($request->department_code) ?: ($query .= " and department_code = ? " AND $qinput[] = $request->department_code);

		$items = DB::select($query.$qfooter,$qinput);
		return response()->json($items);
	}

	public function auto_employee_name(Request $request)
	{
		$qinput = array();
		$query = "
			Select emp_id, emp_code, emp_name
			From employee
			Where emp_name like ?
		";

		$qfooter = " Order by emp_name limit 10 ";
		$qinput[] = '%'.$request->emp_name.'%';
		empty($request->org_id) ?: ($query .= " and org_id = ? " AND $qinput[] = $request->org_id);
		empty($request->position_id) ?: ($query .= " and position_id = ? " AND $qinput[] = $request->position_id);

		$items = DB::select($query.$qfooter,$qinput);
		return response()->json($items);
	}


	public function show($emp_id)
	{
		try {
			$item = Employee::findOrFail($emp_id);
			$item->pqpi_amount = base64_decode($item->pqpi_amount);
			$item->fix_other_amount = base64_decode($item->fix_other_amount);
			$item->mpi_amount = base64_decode($item->mpi_amount);
			$item->pi_amount = base64_decode($item->pi_amount);
			$item->var_other_amount = base64_decode($item->var_other_amount);

			$position= Position::find($item->position_id);
			empty($position) ? $position_name = null : $position_name = $position->position_name;
			$item->position_name = $position_name;

		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Employee not found.']);
		}
		return response()->json($item);
	}

	public function update(Request $request, $emp_id)
	{
		try {
			$item = Employee::findOrFail($emp_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Employee not found.']);
		}

        $validator = Validator::make($request->all(), [
						'emp_code' => 'required|max:255|unique:employee,emp_code,'. $emp_id . ',emp_code',
						'emp_name' => 'required|max:255',
						'working_start_date' => 'date|date_format:Y-m-d',
						'probation_end_date' => 'date|date_format:Y-m-d',
						'acting_end_date' => 'date|date_format:Y-m-d',
						'org_id' => 'integer',
						'position_id' => 'integer',
						'chief_emp_code' => 'max:255',
						'level_id' => 'integer',
						'pqpi_amount' => 'max:100',
						'fix_other_amount' => 'max:100',
						'mpi_amount' => 'max:100',
						'pi_amount' => 'max:100',
						'var_other_amount' => 'max:100',
						//'s_amount' => 'required|numeric|digits_between:1,10',
						'email' => 'required|email|max:100',
						'emp_type' => 'max:50',
						'dotline_code' => 'max:255',
						'has_second_line' => 'max:255',
						'is_active' => 'required|boolean',
						'step' => 'numeric|between:0,199.99'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 400, 'data' => $validator->errors()]);
        } else {
        	empty($request->working_start_date) ? $request->working_start_date = null : $request->working_start_date;
			empty($request->probation_end_date) ? $request->probation_end_date = null : $request->probation_end_date;
			empty($request->acting_end_date) ? $request->acting_end_date = null : $request->acting_end_date;

			// check salary amount is base64
			if ( ! (base64_encode(base64_decode($request->s_amount)) === $request->s_amount) ){
				$item->s_amount = base64_encode($request->s_amount);
			}

			$item->emp_code = $request->emp_code;
			$item->emp_name = $request->emp_name;
			$item->working_start_date = $request->working_start_date;
			$item->probation_end_date = $request->probation_end_date;
			$item->acting_end_date = $request->acting_end_date;
			$item->org_id = $request->org_id;
			$item->position_id = $request->position_id;
			$item->chief_emp_code = $request->chief_emp_code;
			$item->level_id = $request->level_id;
			$item->step = $request->step;
			// $item->s_amount = $item->s_amount;
			$item->s_amount = $request->s_amount;
			$item->email = $request->email;
			$item->emp_type = $request->emp_type;
			$item->dotline_code = $request->dotline_code;
			$item->has_second_line = $request->has_second_line;
			$item->is_active = $request->is_active;
			$item->pqpi_amount = base64_encode($request->pqpi_amount);
			$item->fix_other_amount = base64_encode($request->fix_other_amount);
			$item->mpi_amount = base64_encode($request->mpi_amount);
			$item->pi_amount = base64_encode($request->pi_amount);
			$item->var_other_amount = base64_encode($request->var_other_amount);
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);
	}

	public function show_role($emp_code)
	{
		$items = DB::select("
			SELECT a.appraisal_level_id, a.appraisal_level_name, if(b.emp_code is null,0,1) role_active
			FROM appraisal_level a
			left outer join emp_level b
			on a.appraisal_level_id = b.appraisal_level_id
			and b.emp_code = ?
			order by a.appraisal_level_name
		", array($emp_code));
		return response()->json($items);
	}

	public function assign_role(Request $request, $emp_code)
	{
		DB::table('emp_level')->where('emp_code',$emp_code)->delete();

		if (empty($request->roles)) {
		} else {
			foreach ($request->roles as $r) {
				$item = new EmpLevel;
				$item->appraisal_level_id = $r;
				$item->emp_code = $emp_code;
				$item->created_by = Auth::id();
				$item->save();
			}
		}

		return response()->json(['status' => 200]);
	}

	public function batch_role(Request $request)
	{
		if (empty($request->employees)) {
		} else {
			foreach ($request->employees as $e) {
				$emp = Employee::find($e);
				if (empty($request->roles)) {
				} else {
					foreach ($request->roles as $r) {
						$emp->level_id = $r;
						$emp->updated_by = Auth::id();
						$emp->save();
					}
				}
			}
		}
		return response()->json(['status' => 200]);
	}

	public function destroy($emp_id)
	{
		try {
			$item = Employee::findOrFail($emp_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Employee not found.']);
		}

		try {
			$item->delete();

			// ส่งกลับไปให้ cliant เพื่อนำไปลบ User ใน Liferay //
			try {
				$user = User::findOrFail($item->emp_code);
				$liferayUserId = $user->userId;
			} catch (ModelNotFoundException $e) {
				$liferayUserId = null;
			}

		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Employee is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200, "liferay_user_id"=>$liferayUserId]);

	}

	public function appraisal_level(Request $request)
	{
		$items = DB::select("
			SELECT a.level_id, a.appraisal_level_name, a.is_all_employee, a.district_flag, a.is_org, a.is_individual, a.is_active, a.parent_id, a.is_hr, a.is_self_assign, a.no_weight, b.appraisal_level_name parent_level_name
			FROM appraisal_level a
			LEFT OUTER JOIN appraisal_level b ON a.parent_id = b.level_id
			WHERE a.is_individual = 1
			ORDER BY a.level_id ASC");

		return response()->json($items);
	}
}
