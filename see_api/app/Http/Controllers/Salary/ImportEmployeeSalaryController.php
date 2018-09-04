<?php

namespace App\Http\Controllers\Salary;

use App\Employee;
use App\Position;
use App\Org;
use App\User;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MailController;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ImportEmployeeSalaryController extends Controller
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
			$items = Excel::load($f, function($reader){})->get();
			foreach ($items as $i) {

				$validator = Validator::make($i->toArray(), [
					'employee_code' => 'required|max:255',
					'employee_name' => 'required|max:255',
					'working_start_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'probation_end_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'acting_end_date_yyyy_mm_dd' => 'date|date_format:Y-m-d',
					'organization_code' => 'required',
					'position_code' => 'required',
					'chief_employee_code' => 'max:255',
					'email' => 'required|email|max:100',
					'employee_type' => 'max:50',
					'dotline_code' => 'max:255',
					'has_second_line' => 'max:255'
				]);

				$org = Org::where('org_code',$i->organization_code)->first();
				$position = Position::where('position_code',$i->position_code)->first();

				empty($org) ? $org_id = null : $org_id = $org->org_id;
				empty($position) ? $position_id = null : $position_id = $position->position_id;

				if ($validator->fails()) {
					$errors[] = ['employee_code' => $i->employee_code, 'errors' => $validator->errors()];
				} else {
					$emp = Employee::where('emp_code',$i->employee_code)->first();
					// New Employee //
					if (empty($emp)) {
						$emp = new Employee;
						$emp->emp_code = $i->employee_code;
						$emp->emp_name = $i->employee_name;
						$emp->working_start_date = $i->working_start_date_yyyy_mm_dd;
						$emp->probation_end_date = $i->probation_end_date_yyyy_mm_dd;
						$emp->acting_end_date = $i->acting_end_date_yyyy_mm_dd;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $i->level_id;
						$emp->step = $i->step;
						$emp->chief_emp_code = $i->chief_employee_code;
						$emp->s_amount = $i->salary_amount;
						$emp->email = $i->email;
						$emp->emp_type = $i->employee_type;
						$emp->dotline_code = $i->dotline_code;
						$emp->has_second_line = $i->has_second_line;
						$emp->is_active = 1;
						$emp->created_by = Auth::id();
						$emp->updated_by = Auth::id();
						try {
							$emp->save();

							// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
							$newEmp[] = ["emp_code"=> $i->employee_code, "emp_name"=>$i->employee_name, "email"=>$i->email];
						} catch (Exception $e) {
							$errors[] = ['employee_code' => $i->employee_code, 'errors' => substr($e,0,254)];
						}
					} else {
						// Exists Employee //
						$emp->emp_name = $i->employee_name;
						$emp->working_start_date = $i->working_start_date_yyyy_mm_dd;
						$emp->probation_end_date = $i->probation_end_date_yyyy_mm_dd;
						$emp->acting_end_date = $i->acting_end_date_yyyy_mm_dd;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $i->level_id;
						$emp->step = $i->step;
						$emp->chief_emp_code = $i->chief_employee_code;
						$emp->s_amount = $i->salary_amount;
						$emp->email = $i->email;
						$emp->emp_type = $i->employee_type;
						$emp->dotline_code = $i->dotline_code;
						$emp->has_second_line = $i->has_second_line;
						$emp->is_active = 1;
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
}