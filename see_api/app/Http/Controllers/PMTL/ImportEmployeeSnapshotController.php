<?php

namespace App\Http\Controllers\PMTL;
use App\Http\Controllers\PMTL\QuestionaireDataController;
use App\Http\Controllers\MailController;

use App\Employee;
use App\EmployeeSnapshot;
use App\AppraisalLevel;
use App\Position;
use App\Org;
use App\Roles;
use App\User;
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

class ImportEmployeeSnapshotController extends Controller
{
	protected $qdc_service;
	public function __construct(QuestionaireDataController $qdc_service)
	{
	   $this->middleware('jwt.auth');
	   $this->qdc_service = $qdc_service;
	}

	public function list_level() {
		$items = DB::select("
			SELECT al.level_id, al.appraisal_level_name
			FROM appraisal_level al
			WHERE is_active = 1
		");
		return response()->json($items);
	}

	public function auto_position(Request $request) {
		$items = DB::select("
			SELECT p.position_id, p.position_code
			FROM position p
			INNER JOIN employee_snapshot es ON es.position_id = p.position_id 
			WHERE p.position_code LIKE '%{$request->position_code}%'
			GROUP BY p.position_code
			LIMIT 10
		");
		return response()->json($items);
	}
	
	public function lastStart_date(Request $request)
	{
		$items = DB::select("SELECT DATE_FORMAT(MAX(start_date), '%d/%m/%Y') as start_date FROM employee_snapshot;");
		return response()->json($items[0]);
	}
	public function auto_start_date(Request $request) {
		$items = DB::select("
			SELECT DISTINCT DATE_FORMAT(start_date,'%d/%m/%Y') start_date
			FROM employee_snapshot
			WHERE start_date LIKE '%{$request->start_date}%'
			LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_emp(Request $request) {
		$request->start_date = $this->qdc_service->format_date($request->start_date);
		$emp_name = $this->qdc_service->concat_emp_first_last_code($request->emp_name);

		$level_id = empty($request->level_id) ? "" : "AND es.level_id = '{$request->level_id}'";
		$position_id = empty($request->position_id) ? "" : "AND es.position_id = '{$request->position_id}'";
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$org_id = empty($request->org_id) ? "" : "AND es.org_id = '{$request->org_id}'";
		$job_function = empty($request->job_function_id) ? "" : "AND es.job_function_id = '{$request->job_function_id}'";

		$items = DB::select("
			SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name,' ',es.emp_last_name) emp_name
			FROM employee_snapshot es
			INNER JOIN position p ON p.position_id = es.position_id
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			)
			".$level_id."
			".$position_id."
			".$start_date."
			".$org_id."
			".$job_function."
			LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_chiefEmp(Request $request) {
		$request->start_date = $this->qdc_service->format_date($request->start_date);
		$emp_name = $this->qdc_service->concat_emp_first_last_code($request->emp_name);

		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "INNER JOIN (SELECT e.chief_emp_code FROM employee_snapshot e WHERE e.emp_snapshot_id = '{$request->emp_snapshot_id}') e on es.emp_code = e.chief_emp_code";

		$items = DB::select("
			SELECT es.emp_code, CONCAT(es.emp_first_name,' ',es.emp_last_name) emp_name
			FROM employee_snapshot es
			INNER JOIN position p ON p.position_id = es.position_id
			".$emp_snapshot_id."
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			)
			".$start_date."
			LIMIT 10
		");
		return response()->json($items);
	}

	public function index(Request $request)
	{
		$request->start_date = $this->qdc_service->format_date($request->start_date);

		$level_id = empty($request->level_id) ? "" : "AND al.level_id = '{$request->level_id}'";
		$position_id = empty($request->position_id) ? "" : "AND es.position_id = '{$request->position_id}'";
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND es.emp_snapshot_id = '{$request->emp_snapshot_id}'";
		$org_id = empty($request->org_id) ? "" : "AND es.org_id = '{$request->org_id}'";

		$items = DB::select("
			SELECT es.*,job.job_function_name ,DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, al.appraisal_level_name, p.position_code, o.org_name, es.is_active
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			LEFT OUTER JOIN job_function job ON es.job_function_id = job.job_function_id
			WHERE 1=1
			".$level_id."
			".$position_id."
			".$start_date."
			".$emp_snapshot_id."
			".$org_id."
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
	
	// List Delete And Mend EmployeeSnapshot
	public function index2(Request $request)
	{
		$request->start_date = $this->qdc_service->format_date($request->start_date);

		$level_id = empty($request->level_id) ? "" : "AND al.level_id = '{$request->level_id}'";
		$position_id = empty($request->position_id) ? "" : "AND es.position_id = '{$request->position_id}'";
		$start_date = empty($request->start_date) ? "" : 
				"AND es.start_date BETWEEN '{$request->start_date}' and
				(
					SELECT d.end_date 
					from 
					(
						SELECT STR_TO_DATE('9999-12-31','%Y-%m-%d') end_date
						UNION
						(
							SELECT DISTINCT ems.start_date - INTERVAL 1 DAY end_date 
							from employee_snapshot ems
							WHERE DAY(ems.start_date) = 1 
							AND ems.start_date > '{$request->start_date}' 
							ORDER BY ems.start_date 
							LIMIT 1
						)
					)d ORDER BY d.end_date
					LIMIT 1
				)";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND es.emp_snapshot_id = '{$request->emp_snapshot_id}'";
		$chief_emp_code = empty($request->chief_emp_code) ? "" : "AND es.chief_emp_code = '{$request->chief_emp_code}'";
		$job_function = empty($request->job_function_id) ? "" : "AND es.job_function_id = '{$request->job_function_id}'";
		$more_chief_emp = $request->more_chief_emp == "false" ? "" : "AND es.emp_code = es.chief_emp_code";
		//ค้นหา position ที่ซ้ำกันมากกว่า 1 position
		$more_position = "";
		if($request->more_position == "true"){
			$more_position_list = DB::select("
			SELECT GROUP_CONCAT(emp_snapshot_id) emp_snapshot_id
			from (
				SELECT GROUP_CONCAT(es.emp_snapshot_id) emp_snapshot_id  FROM employee_snapshot es 
				LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
				LEFT OUTER JOIN position p ON p.position_id = es.position_id
				LEFT OUTER JOIN job_function job ON es.job_function_id = job.job_function_id
				WHERE 1=1
				".$level_id."
				".$position_id."
				".$start_date."
				".$emp_snapshot_id."
				".$chief_emp_code."
				".$job_function."
				GROUP BY es.position_id
				HAVING COUNT(es.position_id) >= 2
				)d
			");
			
			$more_position = ($request->more_chief_emp == "false" ? "AND" : "OR")." FIND_IN_SET( es.emp_snapshot_id, '".(!empty($more_position_list) ? $more_position_list[0]->emp_snapshot_id : "")."') ORDER BY p.position_code";
			
			
		}
		
		$items = DB::select("
			SELECT es.*,job.job_function_name ,DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, al.appraisal_level_name, p.position_code, o.org_name, es.is_active
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			LEFT OUTER JOIN job_function job ON es.job_function_id = job.job_function_id
			WHERE 1=1
			".$level_id."
			".$position_id."
			".$start_date."
			".$emp_snapshot_id."
			".$chief_emp_code."
			".$job_function."
			".$more_chief_emp."
			".$more_position."
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

	public function show($emp_snapshot_id)
	{
		try {
			$item = EmployeeSnapshot::findOrFail($emp_snapshot_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'EmployeeSnapshot not found.']);
		}

		$items = DB::select("
			SELECT DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, es.emp_snapshot_id ,es.emp_id, es.emp_code, es.emp_first_name, es.emp_last_name, es.email, es.chief_emp_code, es.distributor_code, es.distributor_name, es.region, al.appraisal_level_name, p.position_code, p.position_name, o.org_code, o.org_name, es.is_active ,j.*
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			LEFT OUTER JOIN job_function j ON es.job_function_id = j.job_function_id 
			WHERE es.emp_snapshot_id = {$emp_snapshot_id}
		");
		return response()->json($items[0]);
	}

	public function list_job(Request $request)
	{
		try {
			$items = JobFunction::select('job_function_id' ,'job_function_name')->get();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Job Fonction not found.']);
		}
		return response()->json($items);
	}

	public function update(Request $request, $emp_snapshot_id)
	{
		try {
			$item = EmployeeSnapshot::findOrFail($emp_snapshot_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Employee Snapshot not found.']);
		}

        $validator = Validator::make($request->all(), [
			'emp_first_name' => 'required|max:100',
			'emp_last_name' => 'required|max:100',
			'email' => 'required|email|max:100',
			'job_function_id' => 'required|max:11',
			'distributor_code' => 'required|max:20',
			'distributor_name' => 'required|max:255',
			'region' => 'required|max:20',
			'is_active' => 'required|integer|between:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 400, 'data' => $validator->errors()]);
        } else {
			$item->emp_first_name = $request->emp_first_name;
			$item->emp_last_name = $request->emp_last_name;
			$item->email = $request->email;
			$item->job_function_id = $request->job_function_id;
			$item->distributor_code = $request->distributor_code;
			$item->distributor_name = $request->distributor_name;
			$item->region = $request->region;
			$item->is_active = $request->is_active;
			$item->updated_by = Auth::id();
			$item->save();
		}
		return response()->json(['status' => 200, 'data' => $item]);
	}
	// Page 
	public function update2(Request $request)
	{
		$errors_validator = [];
		foreach ($request->emp_snapshot as $i) {
			$validator = Validator::make([
				'emp_snapshot_id' => $i['emp_snapshot_id'],
				'emp_first_name' => $i['emp_first_name'],
				'emp_last_name' => $i['emp_last_name'],
				'level_id' => $i['level_id'],
				'job_function_id' => $i['job_function_id'],
				'distributor_code' => $i['distributor_code'],
				'region' => $i['region'],
				'chief_emp_code' => $i['chief_emp_code'],
				'is_active' => $i['is_active']
			], [
				'emp_snapshot_id' => 'required|integer',
				'emp_first_name' => 'required|max:100',
				'emp_last_name' => 'required|max:100',
				'level_id' => 'required|integer',
				'job_function_id' => 'required|integer',
				'distributor_code' => 'required|max:20',
				'region' => 'required|max:20',
				'chief_emp_code' => 'required|max:100',
				'is_active' => 'required|integer|between:0,1'
			]);
	
			if ($validator->fails()) {
				$errors_validator = $validator->errors();
			} else {
				$item = EmployeeSnapshot::find($i['emp_snapshot_id']);
				$item->emp_first_name = $this->qdc_service->trim_text($i['emp_first_name']);
				$item->emp_last_name = $this->qdc_service->trim_text($i['emp_last_name']);
				$item->chief_emp_code = $this->qdc_service->trim_text($i['chief_emp_code']);
				$item->level_id = $i['level_id'];
				$item->job_function_id = $i['job_function_id'];
				$item->distributor_code = $this->qdc_service->trim_text($i['distributor_code']);
				$item->region = $this->qdc_service->trim_text($i['region']);
				$item->is_active = $i['is_active'];
				$item->updated_by = Auth::id();
				$item->save();
			}

		}
        
		if(!empty($errors_validator)){
			return response()->json(['status' => 400, 'data' => $errors_validator]);
		}else{
			return response()->json(['status' => 200]);
		}
	}

	public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '5012M');
		$errors = array();
		$errors_validator = array();
		$newEmp = array();
		$validateoptionOrg = [];
		$validateoptionPosition = [];
		$validateoptionLevel = [];
		$validateoptionJob = [];
		$validateoptionFull = [];
	

		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();

			if (count($items[0])==0) { // เช็คว่ามีข้อมูลใน excel sheet ที่ 1 หรือไม่ หรือ excel ไม่ตรงตาม format
				$errors_template[] = ['Error Template' => 'Please Check', 'errors' => ['Error' => 'Template not set data']];
				return response()->json(['status' => 400, 'errors' => $errors_template]);
			}

			foreach ($items[0] as $i) {
				$validator = Validator::make($i->toArray(), [
					'start_date' => 'required|date|date_format:d.m.Y',
					'employeeid' => 'required|max:255',
					'useraccountcode' => 'required|max:100',
					'employeefirstname' => 'required|max:100',
					'employeelastname' => 'required|max:100',
					'employeeemail' => 'required|email|max:100',
					'line_manager' => 'required|max:255',
					'dist_cd' => 'required|max:20',
					'busnoperationsitedescription' => 'required|max:255',
					'region' => 'required|max:20',
					'organization_code' => 'required|max:100',
					'position' => 'required|max:100',
					'job_function_id' => 'required|max:11',
					'level_id' => 'required|max:11',
					'is_active' => 'required|integer|between:0,1'
				]);

				$i->start_date = $this->qdc_service->format_date($i->start_date);

				$i->useraccountcode = $this->qdc_service->strtolower_text($i->useraccountcode);
				$i->line_manager = $this->qdc_service->strtolower_text($i->line_manager);

				$i->employeeid = $this->qdc_service->trim_text($i->employeeid);
				$i->useraccountcode = $this->qdc_service->trim_text($i->useraccountcode);
				$i->employeefirstname = $this->qdc_service->trim_text($i->employeefirstname);
				$i->employeelastname = $this->qdc_service->trim_text($i->employeelastname);
				$i->employeeemail = $this->qdc_service->trim_text($i->employeeemail);
				$i->line_manager = $this->qdc_service->trim_text($i->line_manager);
				$i->dist_cd = $this->qdc_service->trim_text($i->dist_cd);
				$i->busnoperationsitedescription = $this->qdc_service->trim_text($i->busnoperationsitedescription);
				$i->region = $this->qdc_service->trim_text($i->region);
				$i->organization_code = $this->qdc_service->trim_text($i->organization_code);
				$i->position = $this->qdc_service->trim_text($i->position);

				$org = Org::where('org_code', $i->organization_code)->first();
				$position = Position::where('position_code', $i->position)->first();
				$appraisal_level = AppraisalLevel::where('level_id', $i->level_id)->first();
				$job_function = DB::table('job_function')->where('job_function_id', $i->job_function_id)->first();

				if(empty($org)) {
					$validateoptionOrg = ['Level ID' => ['Organization Code not found']];
					$validateoptionFull[] = "error";
				} else {
					$org_id = $org->org_id;
				}

				if(empty($position)) {
					$validateoptionPosition = ['Level ID' => ['Position not found']];
					$validateoptionFull[] = "error";
				} else {
					$position_id = $position->position_id;
				}

				if(empty($appraisal_level)) {
					$validateoptionLevel = ['Level ID' => ['Level ID not found']];
					$validateoptionFull[] = "error";
				} else {
					$level_id = $appraisal_level->level_id;
					$role = Roles::select("roleId")->where("name" ,$appraisal_level->appraisal_level_name)->first();
				}

				if(empty($job_function)) {
					$validateoptionJob = ['Job Function ID' => ['Job Function ID not found']];
					$validateoptionFull[] = "error";
				} else {
					$job_function_id = $job_function->job_function_id;
				}

				$allItems = collect($validateoptionOrg);
				$allItems = $allItems->merge($validateoptionLevel);
				$allItems = $allItems->merge($validateoptionJob);
				$allItems = $allItems->merge($validateoptionPosition);

				if ($validator->fails()||!empty($validateoptionFull)) {
					$errors_validator[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => collect($validator->errors())->merge($allItems)->all()];
				}else {
					$emp = Employee::where('emp_code', $i->useraccountcode)->first();
					//search --> user in liferay
					$userLiferay = User::where('screenName', $i->useraccountcode)->first();
					if (empty($emp)) {
						$emp = new Employee;
						$emp->emp_code = $i->useraccountcode;
						$emp->emp_name = $i->employeefirstname." ".$i->employeelastname;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $level_id;
						$emp->chief_emp_code = $i->line_manager;
						$emp->email = $i->employeeemail;
						$emp->has_second_line = 0;
						$emp->is_active = 1;
						$emp->created_by = Auth::id();
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
							$emp_snap = EmployeeSnapshot::where('start_date', $i->start_date)
							->where('emp_code', $i->useraccountcode)
							->where('position_id', $position_id)->first();
							if(empty($emp_snap)) {
								$emp_snap = new EmployeeSnapshot;
								$emp_snap->start_date = $i->start_date;
								$emp_snap->emp_id = $i->employeeid;
								$emp_snap->emp_code = $i->useraccountcode;
								$emp_snap->emp_first_name = $i->employeefirstname;
								$emp_snap->emp_last_name = $i->employeelastname;
								$emp_snap->org_id = $org_id;
								$emp_snap->position_id = $position_id;
								$emp_snap->level_id = $level_id;
								$emp_snap->job_function_id = $job_function_id;
								$emp_snap->chief_emp_code = $i->line_manager;
								$emp_snap->email = $i->employeeemail;
								$emp_snap->distributor_code = $i->dist_cd;
								$emp_snap->distributor_name = $i->busnoperationsitedescription;
								$emp_snap->region = $i->region;
								$emp_snap->is_active = $i->is_active;
								$emp_snap->created_by = Auth::id();
								$emp_snap->updated_by = Auth::id();
								try {
									$emp_snap->save();
									// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
									// เพิ่มเฉพาะในกรณีที่ยังไม่มี user ใน Liferay
									if(empty($userLiferay)&&($i->is_active)==1){
										$newEmp[] = [
											"emp_code" => $i->useraccountcode, 
											"emp_name" => $i->employeefirstname." ".$i->employeelastname, 
											"email" => $i->employeeemail,
											"role_id" => $role['roleId']
										];
									}
								} catch (Exception $e) {
									$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
								}
							} else {
								$emp_snap->start_date = $i->start_date;
								$emp_snap->emp_id = $i->employeeid;
								$emp_snap->emp_code = $i->useraccountcode;
								$emp_snap->emp_first_name = $i->employeefirstname;
								$emp_snap->emp_last_name = $i->employeelastname;
								$emp_snap->org_id = $org_id;
								$emp_snap->position_id = $position_id;
								$emp_snap->level_id = $level_id;
								$emp_snap->job_function_id = $job_function_id;
								$emp_snap->chief_emp_code = $i->line_manager;
								$emp_snap->email = $i->employeeemail;
								$emp_snap->distributor_code = $i->dist_cd;
								$emp_snap->distributor_name = $i->busnoperationsitedescription;
								$emp_snap->region = $i->region;
								$emp_snap->is_active = $i->is_active;
								$emp_snap->updated_by = Auth::id();
								try {
									$emp_snap->save();
									// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
									// เพิ่มเฉพาะในกรณีที่ยังไม่มี user ใน Liferay
									if(empty($userLiferay)&&($i->is_active)==1){
										$newEmp[] = [
											"emp_code" => $i->useraccountcode, 
											"emp_name" => $i->employeefirstname." ".$i->employeelastname, 
											"email" => $i->employeeemail,
											"role_id" => $role['roleId']
										];
									}
								} catch (Exception $e) {
									$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
								}
							}

						} catch (Exception $e) {
							$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
						}
					} else {
						$emp->emp_code = $i->useraccountcode;
						$emp->emp_name = $i->employeefirstname." ".$i->employeelastname;
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $level_id;
						$emp->chief_emp_code = $i->line_manager;
						$emp->email = $i->employeeemail;
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
							$emp_snap = EmployeeSnapshot::where('start_date', $i->start_date)
							->where('emp_code', $i->useraccountcode)
							->where('position_id', $position_id)->first();
							if(empty($emp_snap)) {
								$emp_snap = new EmployeeSnapshot;
								$emp_snap->start_date = $i->start_date;
								$emp_snap->emp_id = $i->employeeid;
								$emp_snap->emp_code = $i->useraccountcode;
								$emp_snap->emp_first_name = $i->employeefirstname;
								$emp_snap->emp_last_name = $i->employeelastname;
								$emp_snap->org_id = $org_id;
								$emp_snap->position_id = $position_id;
								$emp_snap->level_id = $level_id;
								$emp_snap->job_function_id = $job_function_id;
								$emp_snap->chief_emp_code = $i->line_manager;
								$emp_snap->email = $i->employeeemail;
								$emp_snap->distributor_code = $i->dist_cd;
								$emp_snap->distributor_name = $i->busnoperationsitedescription;
								$emp_snap->region = $i->region;
								$emp_snap->is_active = $i->is_active;
								$emp_snap->updated_by = Auth::id();
								try {
									$emp_snap->save();
									// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
									// เพิ่มเฉพาะในกรณีที่ยังไม่มี user ใน Liferay
									if(empty($userLiferay)&&($i->is_active)==1){
										$newEmp[] = [
											"emp_code" => $i->useraccountcode, 
											"emp_name" => $i->employeefirstname." ".$i->employeelastname, 
											"email" => $i->employeeemail,
											"role_id" => $role['roleId']
										];
									}
								} catch (Exception $e) {
									$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
								}
							} else {
								$emp_snap->start_date = $i->start_date;
								$emp_snap->emp_id = $i->employeeid;
								$emp_snap->emp_code = $i->useraccountcode;
								$emp_snap->emp_first_name = $i->employeefirstname;
								$emp_snap->emp_last_name = $i->employeelastname;
								$emp_snap->org_id = $org_id;
								$emp_snap->position_id = $position_id;
								$emp_snap->level_id = $level_id;
								$emp_snap->job_function_id = $job_function_id;
								$emp_snap->chief_emp_code = $i->line_manager;
								$emp_snap->email = $i->employeeemail;
								$emp_snap->distributor_code = $i->dist_cd;
								$emp_snap->distributor_name = $i->busnoperationsitedescription;
								$emp_snap->region = $i->region;
								$emp_snap->is_active = $i->is_active;
								$emp_snap->updated_by = Auth::id();
								try {
									$emp_snap->save();
									// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
									// เพิ่มเฉพาะในกรณีที่ยังไม่มี user ใน Liferay
									if(empty($userLiferay)&&($i->is_active)==1){
										$newEmp[] = [
											"emp_code" => $i->useraccountcode, 
											"emp_name" => $i->employeefirstname." ".$i->employeelastname, 
											"email" => $i->employeeemail,
											"role_id" => $role['roleId']
										];
									}
								} catch (Exception $e) {
									$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
								}
							}
						} catch (Exception $e) {
							$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
						}
					}
				}
			}

			// EmployeeSnapshot::whereIn("start_date", $emp_update_date)->whereNotIn("emp_code", $emp_update_code)->whereNotIn("position_id", $emp_update_position)->update(["is_active" => 0]);
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

		return response()->json(['status' => 200, 'errors' => collect($errors)->merge($errors_validator)->all(), "emp"=>$newEmp]);
	}

	public function export(Request $request) {
    	$extension = "xlsx";
    	$fileName = "import_employee_snapshot";

		$request->start_date = $this->qdc_service->format_date($request->start_date);
		$level_id = empty($request->level_id) ? "" : "AND al.level_id = '{$request->level_id}'";
		$position_id = empty($request->position_id) ? "" : "AND es.position_id = '{$request->position_id}'";
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND es.emp_snapshot_id = '{$request->emp_snapshot_id}'";
		$org_id = empty($request->org_id) ? "" : "AND es.org_id = '{$request->org_id}'";

		$emp_snap = DB::select("
		SELECT
			DATE_FORMAT( es.start_date, '%d.%m.%Y' ) as 'Start Date',
			es.emp_id as 'EmployeeId',
			es.emp_code as 'UserAccountCode',
			es.emp_first_name as 'EmployeeFirstName',
			es.emp_last_name as 'EmployeeLastName',
			es.email as 'EmployeeEmail',
			es.level_id as 'Level ID',
			es.job_function_id as 'Job Function ID',
			es.chief_emp_code as 'Line Manager',
			p.position_code as 'Position',
			es.distributor_code as 'DIST_CD',
			es.distributor_name as 'BusnOperationSiteDescription',
			es.region as 'Region',
			o.org_code as 'Organization Code',
			es.is_active as 'Is Active'
		FROM
			employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id 
		WHERE 1 = 1 
			".$level_id."
			".$position_id."
			".$start_date."
			".$emp_snapshot_id."
			".$org_id."
		");

		if(empty($emp_snap)) {
			$emp_snap = DB::select("
			SELECT
				'' as 'Start Date',
				'' as 'EmployeeId',
				'' as 'UserAccountCode',
				'' as 'EmployeeFirstName',
				'' as 'EmployeeLastName',
				'' as 'EmployeeEmail',
				'' as 'Level ID',
				'' as 'Job Function ID',
				'' as 'Line Manager',
				'' as 'Position',
				'' as 'DIST_CD',
				'' as 'BusnOperationSiteDescription',
				'' as 'Region',
				'' as 'Organization Code',
				'' as 'Is Active'
			");
		}

		$org = DB::select("
			SELECT org_code 'Organization Code', org_name 'Organization Name'
			FROM org
			WHERE is_active = 1
			ORDER BY org_code
		");

		$level = DB::select("
			SELECT level_id, appraisal_level_name
			FROM appraisal_level
			WHERE is_active = 1
			ORDER BY level_id
		");

		$job_function = DB::select("
			SELECT job_function_id, job_function_name
			FROM job_function 
			ORDER BY job_function_id
		");

		$data['Employee'] = $emp_snap;
		$data['Org'] = $org;
		$data['Level'] = $level;
		$data['Job Function'] = $job_function;

		$data = json_decode(json_encode($data), true);

		Excel::create($fileName, function($excel) use ($data) {
			foreach ($data as $key => $group) {
				$excel->sheet($key, function($sheet) use ($key, $data) {
					$sheet->fromArray($data[$key], null, 'A1', true);
				});
			}
		})->download($extension);
	}

	public function destroy(Request $request)
	{
		$errors = [];
		foreach($request->emp_snapshot_id as $key => $value) {
			//return response()->json(['key' => $key , 'value'=>$value,'test' => $value['emp_snapshot_id']]);
			try {
				EmployeeSnapshot::where('emp_snapshot_id',$value['emp_snapshot_id'])->delete();
			} catch (Exception $e) {
				$errors[] = 'Cannot delete because this employee is in use.<br>   ['.$value['start_date'].' '.$value['emp_name'].']';
			}
		};
		if(empty($errors)){
			return response()->json(['status' => 200]);
		}else{
			return response()->json(['status' => 400, 'data' => $errors]);
		}

	}



}
