<?php

namespace App\Http\Controllers\PMTL;
use App\Http\Controllers\PMTL\QuestionaireDataController;

use App\Employee;
use App\EmployeeSnapshot;
use App\AppraisalLevel;
use App\Position;
use App\Org;

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
			LIMIT 10
		");
		return response()->json($items);
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

		$level_id = empty($request->level_id) ? "" : "AND es.level_id = '{$request->level_id}'";
		$position_id = empty($request->position_id) ? "" : "AND es.position_id = '{$request->position_id}'";
		$start_date = empty($request->start_date) ? "" : "AND es.start_date = '{$request->start_date}'";
		$org_id = empty($request->org_id) ? "" : "AND es.org_id = '{$request->org_id}'";

		$items = DB::select("
			SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name,'',es.emp_last_name) emp_name
			FROM employee_snapshot es
			WHERE (
				es.emp_first_name LIKE '%{$request->emp_name}%'
				OR es.emp_last_name LIKE '%{$request->emp_name}%'
			)
			".$level_id."
			".$position_id."
			".$start_date."
			".$org_id."
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
			SELECT es.*, DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, al.appraisal_level_name, p.position_code, o.org_name
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
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

	public function show($emp_snapshot_id)
	{
		try {
			$item = EmployeeSnapshot::findOrFail($emp_snapshot_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'EmployeeSnapshot not found.']);
		}

		$items = DB::select("
			SELECT DATE_FORMAT(es.start_date, '%d/%m/%Y') start_date, es.emp_id, es.emp_code, es.emp_first_name, es.emp_last_name, es.email, es.chief_emp_code, es.distributor_code, es.distributor_name, es.region, al.appraisal_level_name, p.position_code, p.position_name, o.org_code, o.org_name
			FROM employee_snapshot es
			LEFT OUTER JOIN appraisal_level al ON al.level_id = es.level_id
			LEFT OUTER JOIN position p ON p.position_id = es.position_id
			LEFT OUTER JOIN org o ON o.org_id = es.org_id
			WHERE es.emp_snapshot_id = {$emp_snapshot_id}
		");
		return response()->json($items[0]);
	}

	public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '1024M');
		$errors = array();
		$errors_validator = array();
		$newEmp = array();
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
					'employeeemail' => 'required|max:100',
					'line_manager' => 'required|max:255',
					'dist_cd' => 'required|max:20',
					'busnoperationsitedescription' => 'required|max:255',
					'region' => 'required|max:20',
					'organization_code' => 'required|max:100',
					'position' => 'required|max:100',
					'job_function_id' => 'required|max:11'
				]);

				$org = Org::where('org_code',$this->qdc_service->trim_text($i->organization_code))->first();
				$position = Position::where('position_code',$this->qdc_service->trim_text($i->position))->first();
				$appraisal_level = AppraisalLevel::where('level_id',$i->job_function_id)->first();

				if(empty($org)) {
					$errors_validator[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['Organization Code' => 'Organization Code not found']];
				} else {
					$org_id = $org->org_id;
				}

				if(empty($position)) {
					$errors_validator[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['Position' => 'Position not found']];
				} else {
					$position_id = $position->position_id;
				}

				if(empty($appraisal_level)) {
					$errors_validator[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['Job Function ID' => 'Job Function ID not found']];
				} else {
					$level_id = $appraisal_level->level_id;
				}

				if ($validator->fails()) {
					$errors_validator[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => $validator->errors()];
				}

				if (!empty($errors_validator)) {
		            return response()->json(['status' => 400, 'errors' => $errors_validator]);
				} else {
					$i->useraccountcode = $this->qdc_service->strtolower_text($i->useraccountcode);
					$i->line_manager = $this->qdc_service->strtolower_text($i->line_manager);

					$emp = Employee::where('emp_code',$this->qdc_service->trim_text($i->useraccountcode))->first();
					if (empty($emp)) {
						$emp = new Employee;
						$emp->emp_code = $this->qdc_service->trim_text($i->useraccountcode);
						$emp->emp_name = $this->qdc_service->trim_text($i->employeefirstname." ".$i->employeelastname);
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $level_id;
						$emp->chief_emp_code = $this->qdc_service->trim_text($i->line_manager);
						$emp->email = $this->qdc_service->trim_text($i->employeeemail);
						$emp->has_second_line = 0;
						$emp->is_active = 1;
						$emp->created_by = Auth::id();
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
							$emp_snap = new EmployeeSnapshot;
							$emp_snap->start_date = $this->qdc_service->format_date($i->start_date);
							$emp_snap->emp_id = $this->qdc_service->trim_text($i->employeeid);
							$emp_snap->emp_code = $this->qdc_service->trim_text($i->useraccountcode);
							$emp_snap->emp_first_name = $this->qdc_service->trim_text($i->employeefirstname);
							$emp_snap->emp_last_name = $this->qdc_service->trim_text($i->employeelastname);
							$emp_snap->org_id = $org_id;
							$emp_snap->position_id = $position_id;
							$emp_snap->level_id = $level_id;
							$emp_snap->chief_emp_code = $this->qdc_service->trim_text($i->line_manager);
							$emp_snap->email = $this->qdc_service->trim_text($i->employeeemail);
							$emp_snap->distributor_code = $this->qdc_service->trim_text($i->dist_cd);
							$emp_snap->distributor_name = $i->busnoperationsitedescription;
							$emp_snap->region = $this->qdc_service->trim_text($i->region);
							$emp_snap->created_by = Auth::id();
							try {
								$emp_snap->save();
								// ส่งกลับไปให้ cliant เพื่อนำไปเพิ่ม User ใน Liferay //
								$newEmp[] = ["emp_code"=> $i->useraccountcode, "emp_name"=>$i->employeefirstname." ".$i->employeelastname, "email"=>$i->employeeemail];
							} catch (Exception $e) {
								$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
							}

						} catch (Exception $e) {
							$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
						}
					} else {
						$emp->emp_code = $this->qdc_service->trim_text($i->useraccountcode);
						$emp->emp_name = $this->qdc_service->trim_text($i->employeefirstname." ".$i->employeelastname);
						$emp->org_id = $org_id;
						$emp->position_id = $position_id;
						$emp->level_id = $level_id;
						$emp->chief_emp_code = $this->qdc_service->trim_text($i->line_manager);
						$emp->email = $this->qdc_service->trim_text($i->employeeemail);
						$emp->updated_by = Auth::id();
						try {
							$emp->save();
							DB::table('employee_snapshot')->where('start_date', '=', $this->qdc_service->format_date($i->start_date))->where('emp_code','=',$this->qdc_service->trim_text($i->useraccountcode))->delete();
							$emp_snap = new EmployeeSnapshot;
							$emp_snap->start_date = $this->qdc_service->format_date($i->start_date);
							$emp_snap->emp_id = $this->qdc_service->trim_text($i->employeeid);
							$emp_snap->emp_code = $this->qdc_service->trim_text($i->useraccountcode);
							$emp_snap->emp_first_name = $this->qdc_service->trim_text($i->employeefirstname);
							$emp_snap->emp_last_name = $this->qdc_service->trim_text($i->employeelastname);
							$emp_snap->org_id = $org_id;
							$emp_snap->position_id = $position_id;
							$emp_snap->level_id = $level_id;
							$emp_snap->chief_emp_code = $this->qdc_service->trim_text($i->line_manager);
							$emp_snap->email = $this->qdc_service->trim_text($i->employeeemail);
							$emp_snap->distributor_code = $this->qdc_service->trim_text($i->dist_cd);
							$emp_snap->distributor_name = $i->busnoperationsitedescription;
							$emp_snap->region = $this->qdc_service->trim_text($i->region);
							$emp_snap->created_by = Auth::id();
							try {
								$emp_snap->save();
							} catch (Exception $e) {
								$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
							}
						} catch (Exception $e) {
							$errors[] = ['UserAccountCode' => $i->useraccountcode, 'errors' => ['validate' => substr($e,0,254)]];
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

	public function export() {
    	$extension = "xlsx";
    	$fileName = "import_employee_snapshot";

		$emp_snap = [
			'Start Date',
			'EmployeeId',
			'UserAccountCode',
			'EmployeeFirstName',
			'EmployeeLastName',
			'EmployeeEmail',
			'Job Function ID',
			'Line Manager',
			'Position',
			'DIST_CD',
			'BusnOperationSiteDescription',
			'Region',
			'Organization Code'
		];

		$org = DB::select("
			SELECT org_code 'Organization Code', org_name 'Organization Name'
			FROM org
			WHERE is_active = 1
			ORDER BY org_code
		");

		$level = DB::select("
			SELECT level_id 'Job Function ID', appraisal_level_name 'Job Function Name'
			FROM appraisal_level
			WHERE is_active = 1
			ORDER BY level_id
		");

		$data['Employee'] = $emp_snap;
		$data['Org'] = $org;
		$data['Job Function'] = $level;

		$data = json_decode(json_encode($data), true);

		Excel::create($fileName, function($excel) use ($data) {
			foreach ($data as $key => $group) {
				$excel->sheet($key, function($sheet) use ($key, $data) {
					$sheet->fromArray($data[$key], null, 'A1', true);
				});
			}
		})->download($extension);
	}
}