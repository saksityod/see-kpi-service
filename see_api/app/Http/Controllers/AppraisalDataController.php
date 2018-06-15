<?php

namespace App\Http\Controllers;

use App\CDS;
use App\AppraisalItemResult;
use App\Employee;
use App\AppraisalStructure;

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

class AppraisalDataController extends Controller
{

	public function __construct()
	{

		$this->middleware('jwt.auth');
	}
	
	public function structure_list()
	{
		$items = DB::select("
			Select s.structure_id, s.structure_name
			From appraisal_structure s, form_type t
			Where s.form_id = t.form_id
			And t.form_name = 'Deduct Score'
			And s.is_active = 1 order by structure_id
			");
		return response()->json($items);
	}

	public function structure_list2()
	{
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select structure_id, structure_name
				From appraisal_structure
				where structure_id = 3
				or structure_id = 4
				or structure_id = 5
				order by structure_id 
				");
		} else {
			$items = DB::select("
				Select structure_id, structure_name
				From appraisal_structure
				where structure_id = 3
				");
		}

		return response()->json($items);
	}
	
	public function period_list()
	{
		$items = DB::select("
			select period_id, appraisal_period_desc
			From appraisal_period
			Where appraisal_year = (select current_appraisal_year from system_config where config_id = 1)
			order by period_id
			");
		return response()->json($items);
	}	
	
	public function al_list()
	{
		$items = DB::select("
			select level_id, appraisal_level_name
			from appraisal_level
			where is_active = 1
			and is_individual = 1
			order by level_id
			");
		return response()->json($items);
	}

	public function al_list_emp(Request $request)
	{
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select level_id, appraisal_level_name
				From appraisal_level
				Where is_active = 1
				and is_individual = 1 
				Order by level_id desc
				");
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			//$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array

			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			$dotline_code = Auth::id();

			$items = DB::select("
				select l.level_id, l.appraisal_level_name
				from appraisal_level l
				inner join employee e
				on e.level_id = l.level_id
				where e.emp_code in ({$in_emp})
				and l.is_individual = 1
				and l.is_active = 1
				and e.is_active = 1
				group by l.level_id desc
				");
		}

		return response()->json($items);
	}

	public function al_list_emp_org(Request $request)
	{
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array($request->emp_code));

		if ($all_emp[0]->count_no > 0 && empty($request->level_id) ) {
			$items = DB::select("
				select level_id, appraisal_level_name
				From appraisal_level
				Where is_active = 1
				Order by level_id
				");
		} else if($all_emp[0]->count_no > 0 && !empty($request->level_id)){
			$items = DB::select("
				select l.level_id, l.appraisal_level_name
				from appraisal_level l
				inner join org o
				on l.level_id = o.level_id
				inner join employee e
				on o.org_id = e.org_id
				where e.level_id = ?
				and l.is_org = 1
				and l.is_active = 1
				and o.is_active = 1
				and e.is_active = 1
				group by l.level_id
				", array($request->level_id));
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			//$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array

			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			//$dotline_code = Auth::id();

			$items = DB::select("
				select l.level_id, l.appraisal_level_name
				from appraisal_level l
				inner join org o
				on l.level_id = o.level_id
				inner join employee e
				on o.org_id = e.org_id
				where e.emp_code in ({$in_emp})
				and e.level_id = ?
				and l.is_org = 1
				and l.is_active = 1
				and o.is_active = 1
				and e.is_active = 1
				group by l.level_id
				", array($request->level_id));
		}

		return response()->json($items);
	}

	public function list_org_for_emp(Request $request)
	{
		empty($request->level_id) ? $level_id = "" : $level_id = $request->level_id;
		empty($request->level_id_emp) ? $level_id_emp = "" : $level_id_emp = $request->level_id_emp;
		empty($request->emp_code) ? $emp_code = "" : $emp_code = $request->emp_code;

		$all_emp = DB::select("
			SELECT sum(l.is_all_employee) count_no
			from appraisal_level l
			inner join org o on o.level_id = l.level_id
			inner join employee e on e.org_id = o.org_id
			where e.emp_code = '{$emp_code}'
			");

		if ($all_emp[0]->count_no > 0)
		{
			$items = DB::select("
				SELECT o.org_id, o.org_name
				FROM org o
				INNER JOIN employee e ON e.org_id = o.org_id
				WHERE o.level_id = '{$level_id}'
				AND e.level_id = '{$level_id_emp}'
				GROUP BY o.org_id
				ORDER BY o.org_name ASC
				");
		}
		else
		{
			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			//$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array

			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			$items = DB::select("
				SELECT o.org_id, o.org_name
				FROM org o
				INNER JOIN employee e ON e.org_id = o.org_id
				WHERE o.level_id = '{$level_id}'
				AND e.emp_code in ({$in_emp})
				AND e.level_id = '{$level_id_emp}'
				GROUP BY o.org_id
				ORDER BY o.org_name ASC
			");
		}
		return response()->json($items);
	}

	public function auto_emp_name_new(Request $request)
	{		
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		empty($request->org_id) ? $org = "" : $org = " and org_id = " . $request->org_id . " ";
		empty($request->level_id) ? $level_id = "" : $level_id = " and level_id = " . $request->level_id . " ";

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select emp_id, emp_code, emp_name
				From employee
				Where emp_name like ?
				and is_active = 1
			" . $org . "
			" . $level_id . "
				Order by emp_name
			", array('%'.$request->emp_name.'%'));
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			//$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array

			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			$items = DB::select("
				Select emp_id, emp_code, emp_name
				From employee
				Where emp_code in ({$in_emp})
				And emp_name like ?
			" . $org . "
			" . $level_id . "			
				and is_active = 1
				Order by emp_name
			", array('%'.$request->emp_name.'%'));
		}		
		return response()->json($items);
	}

	public function auto_position_name2(Request $request)
	{
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		empty($request->org_id) ? $org = "" : $org = " and a.org_id = " . $request->org_id . " ";
		
		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select distinct b.position_id, b.position_name
				From employee a left outer join position b
				on a.position_id = b.position_id
				Where position_name like ?
				and emp_name like ?
				and a.is_active = 1
				and b.is_active = 1
				" . $org . "
				Order by position_name
				limit 10
				",array('%'.$request->position_name.'%','%'.$request->emp_name.'%'));
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			//$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array

			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			$items = DB::select("
				Select distinct b.position_id, b.position_name
				From employee a left outer join position b
				on a.position_id = b.position_id
				Where a.emp_code = ({$in_emp})
				and position_name like ?
				and emp_name like ?
				and a.is_active = 1
				" . $org . "
				and b.is_active = 1
				Order by position_name
				limit 10
				", array('%'.$request->position_name.'%','%'.$request->emp_name.'%'));
		}
		return response()->json($items);
	}
	
	public function appraisal_type_list()
	{
		$items = DB::select("
			select *
			from appraisal_type		
			order by appraisal_type_id
			");
		return response()->json($items);
	}

	public function auto_appraisal_item(Request $request)
	{
		$qinput = array();
		$query = "
		Select distinct a.item_id, a.item_name
		From appraisal_item a left outer join appraisal_item_level b
		on a.item_id = b.item_id
		Where item_name like ?
		";
		
		$qfooter = " Order by item_name limit 10 ";
		$qinput[] = '%'.$request->item_name.'%';
		empty($request->structure_id) ?: ($query .= " and structure_id = ? " AND $qinput[] = $request->structure_id);
		empty($request->level_id) ?: ($query .= " and b.level_id = ? " AND $qinput[] = $request->level_id);
		
		$items = DB::select($query.$qfooter,$qinput);
		return response()->json($items);		
	}
	
	public function auto_emp_name(Request $request)
	{
		// $items = DB::select("
			// Select distinct e.emp_id, e.emp_name
			// From employee e
			// where e.emp_name like ? and e.is_active = 1
			// Order by e.emp_name	limit 10
		// ", array('%'.$request->emp_name.'%'));
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		empty($request->org_id) ? $org = "" : $org = " and org_id = " . $request->org_id . " ";
		
		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select emp_id, emp_code, emp_name
				From employee
				Where emp_name like ?
				and is_active = 1
				" . $org . "
				Order by emp_name
				", array('%'.$request->emp_name.'%'));
		} else {
			$items = DB::select("
				Select emp_id, emp_code, emp_name
				From employee
				Where (chief_emp_code = ? or emp_code = ?)
				And emp_name like ?
				" . $org . "				
				and is_active = 1
				Order by emp_name
				", array($emp->emp_code, $emp->emp_code,'%'.$request->emp_name.'%'));
		}				
		return response()->json($items);
	}
	
	public function import(Request $request)
	{
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		$errors = array();
		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();

			$structure_id = empty($items[0]->structure_id) ? null : $items[0]->structure_id;
			$target_score = AppraisalStructure::find($structure_id);

			if(empty($target_score)) {
				$data_target = 15;
			} else if($target_score->form_id==2) {
				$data_target = $target_score->nof_target_score;
			} else {
				$data_target = 15;
			}

			//return response()->json($data_target);

			foreach ($items as $i) {
				$validator = Validator::make($i->toArray(), [
					'emp_result_id' => 'required|integer',
					'employee_id' => 'required|integer',
					'period_id' => 'required|integer',
					'item_id' => 'required|integer',
					'data_value' => 'required|numeric|between:0,'.$data_target.'',
				]);

				if ($validator->fails()) {
					$errors[] = ['employee_code' => $i->employee_code, 'errors' => $validator->errors()];
				} else {
					try {

						$chief_emp = Employee::find($i->employee_code);
						if($all_emp[0]->count_no > 0) {
							AppraisalItemResult::where("emp_result_id",$i->emp_result_id)->where("emp_id",$i->employee_id)->where("period_id",$i->period_id)->where('item_id',$i->item_id)->update(['first_score' => $i->data_value,'second_score' => $i->data_value, 'score' => $i->data_value, 'updated_by' => Auth::id()]);
						} else if($chief_emp->chief_emp_code==auth::id()) {
							// chief first
							$second = DB::select("
								select second_score
								from appraisal_item_result
								where emp_result_id = {$i->emp_result_id}
								and emp_id = {$i->employee_id}
								and period_id = {$i->period_id}
								and item_id ={$i->item_id}
							");
							$score = ($second[0]->second_score + $i->data_value) / 2;

							AppraisalItemResult::where("emp_result_id",$i->emp_result_id)->where("emp_id",$i->employee_id)->where("period_id",$i->period_id)->where('item_id',$i->item_id)->update(['first_score' => $i->data_value, 'score' => $score, 'updated_by' => Auth::id()]);
						} else {
							// chief second
							$first = DB::select("
								select first_score
								from appraisal_item_result
								where emp_result_id = {$i->emp_result_id}
								and emp_id = {$i->employee_id}
								and period_id = {$i->period_id}
								and item_id ={$i->item_id}
							");
							$score = ($first[0]->first_score + $i->data_value) / 2;
							
							AppraisalItemResult::where("emp_result_id",$i->emp_result_id)->where("emp_id",$i->employee_id)->where("period_id",$i->period_id)->where('item_id',$i->item_id)->update(['second_score' => $i->data_value, 'score' => $score, 'updated_by' => Auth::id()]);
						}

						$items = DB::select("
							select a.item_result_id, ifnull(a.max_value,0) max_value, a.actual_value, ifnull(a.deduct_score_unit,0) deduct_score_unit
							from appraisal_item_result a
							left outer join emp_result b
							on a.emp_result_id = b.emp_result_id
							left outer join appraisal_item c
							on a.item_id = c.item_id
							left outer join appraisal_structure d
							on c.structure_id = d.structure_id
							where d.form_id = 3
							and a.period_id = ?
							and a.emp_id = ?
							and a.item_id = ?
							", array($i->period_id, $i->employee_id, $i->item_id));
						
						foreach ($items as $ai) {
							$uitem = AppraisalItemResult::find($ai->item_result_id);
							if (($ai->max_value - $ai->actual_value) > 0) {
								$uitem->over_value = 0;
								$uitem->weigh_score = 0;
							} else {
								$uitem->over_value = $ai->max_value - $ai->actual_value;
								$uitem->weigh_score = ($ai->max_value - $ai->actual_value) * $ai->deduct_score_unit;
							}
							$uitem->updated_by = Auth::id();
							$uitem->save();
						}						
					} catch (Exception $e) {
						$errors[] = ['employee_code' => $i->employee_code, 'errors' => substr($e,0,254)];
					}

				}					
			}
		}
		return response()->json(['status' => 200, 'errors' => $errors]);
	}		
	
	public function export_bk(Request $request)
	{

		$qinput = array();
		$query = "
		select p.appraisal_period_desc, p.period_id, s.structure_name, s.structure_id, i.item_id, i.item_name, e.emp_id, e.emp_code, e.emp_name, r.actual_value, er.emp_result_id
		from appraisal_item_result r, employee e, appraisal_period p, appraisal_item i, appraisal_structure s, form_type f, emp_result er
		where r.emp_id = e.emp_id 
		and r.period_id = p.period_id
		and r.item_id = i.item_id
		and i.structure_id = s.structure_id
		and r.emp_result_id = er.emp_result_id
		and s.form_id = f.form_id
		and f.form_name = 'Deduct Score'			
		";

		empty($request->structure_id) ?: ($query .= " AND i.structure_id = ? " AND $qinput[] = $request->structure_id);
		empty($request->level_id) ?: ($query .= " And exists (select 1 from appraisal_item_level lv where i.item_id = lv.item_id and lv.level_id = ?) " AND $qinput[] = $request->level_id);
		empty($request->item_id) ?: ($query .= " And r.item_id = ? " AND $qinput[] = $request->item_id);
		empty($request->period_id) ?: ($query .= " And r.period_id = ? " AND $qinput[] = $request->period_id);
		empty($request->emp_id) ?: ($query .= " And r.emp_id = ? " AND $qinput[] = $request->emp_id);
		
		$qfooter = " Order by r.period_id, s.structure_name, i.item_name, e.emp_code ";
		
		$items = DB::select($query . $qfooter, $qinput);

		// echo $query . $qfooter;
		// echo "<br>";
		// print_r($qinput);

		
		$filename = "Appraisal_Data";  //. date('dm') .  substr(date('Y') + 543,2,2);
		$x = Excel::create($filename, function($excel) use($items, $filename) {
			$excel->sheet($filename, function($sheet) use($items) {
				
				$sheet->appendRow(array('Emp Result ID', 'Employee ID', 'Structure ID', 'Structure Name', 'Period ID', 'Period Name', 'Item ID', 'Item Name', 'Data Value'));

				foreach ($items as $i) {
					$sheet->appendRow(array(
						$i->emp_result_id,
						$i->emp_id, 
						$i->structure_id, 
						$i->structure_name, 
						$i->period_id, 
						$i->appraisal_period_desc,
						$i->item_id,
						$i->item_name,
						$i->actual_value
					));
				}
			});

		})->export('xls');	

	}	

	public function export(Request $request)
	{
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		$qinput = array();

		if($all_emp[0]->count_no > 0) {
			$query = "
			select p.appraisal_period_desc, p.period_id, s.structure_name, s.structure_id, i.item_id, i.item_name, e.emp_id, e.emp_code, e.emp_name, r.actual_value, er.emp_result_id, po.position_name, o.org_name, al.appraisal_level_name
			from appraisal_item_result r, employee e, appraisal_period p, appraisal_item i, appraisal_structure s, form_type f, emp_result er, org o, position po, appraisal_level al
			where r.emp_id = e.emp_id 
			and r.period_id = p.period_id
			and r.item_id = i.item_id
			and i.structure_id = s.structure_id
			and r.emp_result_id = er.emp_result_id
			and s.form_id = f.form_id
			and r.org_id = o.org_id
			and r.position_id = po.position_id
			and r.level_id = al.level_id
			and (f.form_name = 'Deduct Score' or f.form_name ='Quality')
			and er.appraisal_type_id = 2
			";

			empty($request->current_appraisal_year) ?: ($query .= " AND p.appraisal_year = ? " AND $qinput[] = $request->current_appraisal_year);
			empty($request->period_id) ?: ($query .= " And r.period_id = ? " AND $qinput[] = $request->period_id);
			empty($request->level_id) ?: ($query .= " And o.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_emp) ?: ($query .= " And r.level_id = ? " AND $qinput[] = $request->level_id_emp);
			//empty($request->item_id) ?: ($query .= " And r.item_id = ? " AND $qinput[] = $request->item_id);
			empty($request->emp_id) ?: ($query .= " And r.emp_id = ? " AND $qinput[] = $request->emp_id);
			empty($request->org_id) ?: ($query .= " And r.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " And r.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->structure_id) ?: ($query .= " And s.structure_id = ? " AND $qinput[] = $request->structure_id);
			
			$qfooter = " Order by r.period_id, s.structure_name, i.item_name, e.emp_code ";
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			$dotline_code = Auth::id();

			$query = "
			select p.appraisal_period_desc, p.period_id, s.structure_name, s.structure_id, i.item_id, i.item_name, e.emp_id, e.emp_code, e.emp_name, r.actual_value, er.emp_result_id, po.position_name, o.org_name, al.appraisal_level_name
			from appraisal_item_result r, employee e, appraisal_period p, appraisal_item i, appraisal_structure s, form_type f, emp_result er, org o, position po, appraisal_level al
			where r.emp_id = e.emp_id 
			and r.period_id = p.period_id
			and r.item_id = i.item_id
			and i.structure_id = s.structure_id
			and r.emp_result_id = er.emp_result_id
			and s.form_id = f.form_id
			and r.org_id = o.org_id
			and r.position_id = po.position_id
			and r.level_id = al.level_id
			and (f.form_name = 'Deduct Score' or f.form_name ='Quality')
			and er.appraisal_type_id = 2
			and (e.emp_code in ({$in_emp}) or e.dotline_code = '{$dotline_code}')
			";

			empty($request->current_appraisal_year) ?: ($query .= " AND p.appraisal_year = ? " AND $qinput[] = $request->current_appraisal_year);
			empty($request->period_id) ?: ($query .= " And r.period_id = ? " AND $qinput[] = $request->period_id);
			empty($request->level_id) ?: ($query .= " And o.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_emp) ?: ($query .= " And r.level_id = ? " AND $qinput[] = $request->level_id_emp);
			//empty($request->item_id) ?: ($query .= " And r.item_id = ? " AND $qinput[] = $request->item_id);
			empty($request->emp_id) ?: ($query .= " And r.emp_id = ? " AND $qinput[] = $request->emp_id);
			empty($request->org_id) ?: ($query .= " And r.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " And r.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->structure_id) ?: ($query .= " And s.structure_id = ? " AND $qinput[] = $request->structure_id);
			
			$qfooter = " Order by r.period_id, s.structure_name, i.item_name, e.emp_code ";
		}
		
		$items = DB::select($query . $qfooter, $qinput);

		// echo $query . $qfooter;
		// echo "<br>";
		// print_r($qinput);

		
		$filename = "Appraisal_Data";  //. date('dm') .  substr(date('Y') + 543,2,2);
		$x = Excel::create($filename, function($excel) use($items, $filename) {
			$excel->sheet($filename, function($sheet) use($items) {
				
				$sheet->appendRow(array('Emp Result ID', 'Employee ID', 'Employee Code', 'Employee Name', 'Organization Name', 'Position Name', 'Appraisal Level Name', 'Structure ID', 'Structure Name', 'Period ID', 'Period Name', 'Item ID', 'Item Name', 'Data Value'));

				foreach ($items as $i) {
					$sheet->appendRow(array(
						$i->emp_result_id,
						$i->emp_id, 
						$i->emp_code,
						$i->emp_name,
						$i->org_name,
						$i->position_name,
						$i->appraisal_level_name,
						$i->structure_id, 
						$i->structure_name, 
						$i->period_id, 
						$i->appraisal_period_desc,
						$i->item_id,
						$i->item_name,
						$i->actual_value
					));
				}
			});

		})->export('xls');	

	}	

	
	public function index(Request $request)
	{
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		$qinput = array();

		if($all_emp[0]->count_no > 0) {
			$query = "
			select p.appraisal_period_desc, s.structure_name, i.item_name, e.emp_code, e.emp_name, r.actual_value, er.emp_result_id,
			al.appraisal_level_name, o.org_name, po.position_name
			from appraisal_item_result r, employee e, appraisal_period p, appraisal_item i, appraisal_structure s, form_type f, emp_result er, org o, appraisal_level al, position po
			where r.emp_id = e.emp_id 
			and r.period_id = p.period_id
			and r.item_id = i.item_id
			and i.structure_id = s.structure_id
			and r.emp_result_id = er.emp_result_id
			and s.form_id = f.form_id			
			and r.org_id = o.org_id
			and r.level_id = al.level_id
			and r.position_id = po.position_id
			and (f.form_name = 'Deduct Score' or f.form_name ='Quality')
			and er.appraisal_type_id = 2
			"; 

			empty($request->current_appraisal_year) ?: ($query .= " AND p.appraisal_year = ? " AND $qinput[] = $request->current_appraisal_year);
			empty($request->period_id) ?: ($query .= " And r.period_id = ? " AND $qinput[] = $request->period_id);
			empty($request->level_id) ?: ($query .= " And o.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_emp) ?: ($query .= " And r.level_id = ? " AND $qinput[] = $request->level_id_emp);
			//empty($request->item_id) ?: ($query .= " And r.item_id = ? " AND $qinput[] = $request->item_id);
			empty($request->emp_id) ?: ($query .= " And r.emp_id = ? " AND $qinput[] = $request->emp_id);
			empty($request->org_id) ?: ($query .= " And r.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " And r.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->structure_id) ?: ($query .= " And s.structure_id = ? " AND $qinput[] = $request->structure_id);
			
			$qfooter = " Order by r.period_id, s.structure_name, i.item_name, e.emp_code ";

		} else {
			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
				", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
					");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			$dotline_code = Auth::id();

			$query = "
			select p.appraisal_period_desc, s.structure_name, i.item_name, e.emp_code, e.emp_name, r.actual_value, er.emp_result_id,
			al.appraisal_level_name, o.org_name, po.position_name
			from appraisal_item_result r, employee e, appraisal_period p, appraisal_item i, appraisal_structure s, form_type f, emp_result er, org o, appraisal_level al, position po
			where r.emp_id = e.emp_id 
			and r.period_id = p.period_id
			and r.item_id = i.item_id
			and i.structure_id = s.structure_id
			and r.emp_result_id = er.emp_result_id
			and s.form_id = f.form_id			
			and r.org_id = o.org_id
			and r.level_id = al.level_id
			and r.position_id = po.position_id
			and (f.form_name = 'Deduct Score' or f.form_name ='Quality')
			and er.appraisal_type_id = 2
			and (e.emp_code in ({$in_emp}) or e.dotline_code = '{$dotline_code}')
			"; 

			empty($request->current_appraisal_year) ?: ($query .= " AND p.appraisal_year = ? " AND $qinput[] = $request->current_appraisal_year);
			empty($request->period_id) ?: ($query .= " And r.period_id = ? " AND $qinput[] = $request->period_id);
			empty($request->level_id) ?: ($query .= " And o.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_emp) ?: ($query .= " And r.level_id = ? " AND $qinput[] = $request->level_id_emp);
			//empty($request->item_id) ?: ($query .= " And r.item_id = ? " AND $qinput[] = $request->item_id);
			empty($request->emp_id) ?: ($query .= " And r.emp_id = ? " AND $qinput[] = $request->emp_id);
			empty($request->org_id) ?: ($query .= " And r.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " And r.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->structure_id) ?: ($query .= " And s.structure_id = ? " AND $qinput[] = $request->structure_id);
			
			$qfooter = " Order by r.period_id, s.structure_name, i.item_name, e.emp_code ";
		}
		
		// echo $query . $qfooter;
		// echo "<br>";
		// print_r($qinput);
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
	
	public function calculate_weight(Request $request)
	{
		$items = DB::select("
			select a.appraisal_item_result_id, ifnull(a.max_value,0) max_value, a.actual_value, ifnull(a.deduct_score_unit,0) deduct_score_unit
			from appraisal_item_result a
			left outer join emp_result b
			on a.emp_result_id = b.emp_result_id
			left outer join appraisal_item c
			on a.item_id = c.item_id
			left outer join appraisal_structure d
			on c.structure_id = d.structure_id
			where d.form_id = 3
			and a.period_id = ?
			and a.emp_code = ?
			and a.item_id = ?
			", array($request->period_id, $request->emp_code, $request->item_id));
		
		foreach ($items as $i) {
			$uitem = AppraisalItemResult::find($i->appraisal_item_result_id);
			if (($i->max_value - $i->actual_value) > 0) {
				$uitem->over_value = 0;
				$uitem->weigh_score = 0;
			} else {
				$uitem->over_value = $i->max_value - $i->actual_value;
				$uitem->weigh_score = ($i->max_value - $i->actual_value) * $i->deduct_score_unit;
			}
			$uitem->updated_by = Auth::id();
			$uitem->save();
		}
		
		return response()->json(['status' => 200]);

	}	
	
}
