<?php

namespace App\Http\Controllers\Appraisal360degree;

use App\SystemConfiguration;
use App\Employee;
use App\Org;
use App\CompetencyResult;
use App\AppraisalItemResult;

use Auth;
use DB;
use DateTime;
use File;
use Validator;
use Excel;
use Config;
use Mail;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalGroupController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
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

		$system_config = SystemConfiguration::where('current_appraisal_year', $request->appraisal_year)->first();

		//ja start
		$auth_group = Auth::id();
		$query_group = "select (case when l.is_all_employee = 1 or l.is_hr = 1 then 5
				when e.emp_code = y.you_chief_emp or e.emp_code = y.you_has_second then 1
				when y.you_emp_code = e.chief_emp_code or y.you_emp_code = hs.chief_emp_code then 2
				when e.emp_code = y.you_emp_code then 4
				else 3 end
				) as group_id
				, (case when l.is_all_employee = 1 or l.is_hr = 1
					then (select assessor_group_name from assessor_group where assessor_group_id = 5)
				when e.emp_code = y.you_chief_emp or e.emp_code = y.you_has_second
					then (select assessor_group_name from assessor_group where assessor_group_id = 1)
				when y.you_emp_code = e.chief_emp_code or y.you_emp_code = hs.chief_emp_code
					then (select assessor_group_name from assessor_group where assessor_group_id = 2)
				when e.emp_code = y.you_emp_code
					then (select assessor_group_name from assessor_group where assessor_group_id = 4)
				else (select assessor_group_name from assessor_group where assessor_group_id = 3) end
				) as group_name
				, y.emp_result_id
				from employee e
				inner join appraisal_level l on e.level_id = l.level_id
				inner join (select emp_code, chief_emp_code from employee) hs on hs.emp_code = e.chief_emp_code
				cross join (select em.emp_code as you_emp_code
					, em.chief_emp_code as you_chief_emp
					, (case when em.has_second_line = 1 then hs.chief_emp_code else 'No' end) as you_has_second
					, e.emp_result_id
					from employee em
					inner join emp_result e on em.emp_id = e.emp_id
					inner join (select emp_code, chief_emp_code from employee) hs on hs.emp_code = em.chief_emp_code) y
				where e.emp_code = '{$auth_group}' ";

		// return response()->json(['status' => 200]);
		//ja end

		if ($all_emp[0]->count_no > 0) {
			$query = "
				select a.emp_result_id, a.emp_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
				, gro.group_id, gro.group_name
				from emp_result a
				left outer join employee b
				on a.emp_id = b.emp_id
				left outer join appraisal_level d
				on a.level_id = d.level_id
				left outer join appraisal_type e
				on a.appraisal_type_id = e.appraisal_type_id
				left outer join appraisal_stage f
				on a.stage_id = f.stage_id
				left outer join appraisal_period g
				on a.period_id = g.period_id
				left outer join position p
				on a.position_id = p.position_id
				left outer join org o
				on a.org_id = o.org_id
				left outer join org po
				on o.parent_org_code = po.org_code
				left outer join (".$query_group.") gro on gro.emp_result_id = a.emp_result_id
				where d.is_hr = 0
			";

			empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
			empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
			empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
			empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
			empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);
			/*
			echo $query. " order by period_id,emp_code,org_code  asc ";
			print_r($qinput);
			*/
			$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

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
			if ($request->appraisal_type_id == 2) {
				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
					, gro.group_id, gro.group_name
					from emp_result a
					left outer join employee b
					on a.emp_id = b.emp_id
					left outer join appraisal_level d
					on a.level_id = d.level_id
					left outer join appraisal_type e
					on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f
					on a.stage_id = f.stage_id
					left outer join appraisal_period g
					on a.period_id = g.period_id
					left outer join position p
					on a.position_id = p.position_id
					left outer join org o
					on a.org_id = o.org_id
					left outer join org po
					on o.parent_org_code = po.org_code
					left outer join (".$query_group.") gro on gro.emp_result_id = a.emp_result_id
					where d.is_hr = 0
					and (b.emp_code in ({$in_emp}) or b.dotline_code = '{$dotline_code}')
				";

				empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
				empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
				empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
				empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
				empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
				empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
				empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
				empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);

				/*
				echo $query. " order by period_id,emp_code,org_code  asc ";
				echo "<br>";
				print_r($qinput);
				*/

				$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

			} else {

				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
					, gro.group_id, gro.group_name
					from emp_result a
					left outer join employee b
					on a.emp_id = b.emp_id
					left outer join appraisal_level d
					on a.level_id = d.level_id
					left outer join appraisal_type e
					on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f
					on a.stage_id = f.stage_id
					left outer join appraisal_period g
					on a.period_id = g.period_id
					left outer join position p
					on a.position_id = p.position_id
					left outer join org o
					on a.org_id = o.org_id
					left outer join org po
					on o.parent_org_code = po.org_code
					left outer join (".$query_group.") gro on gro.emp_result_id = a.emp_result_id
					where d.is_hr = 0
				";

				empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
				empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
				empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
				empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
				empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
				empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
				empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
				empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);

				$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

			}

		}


		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);

		$groups = array();
		foreach ($itemsForCurrentPage as $item) {
			$key = "p".$item->period_id;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'appraisal_period_desc' => $item->appraisal_period_desc,
					'count' => 1,
				);
			} else {
				$groups[$key]['items'][] = $item;
				$groups[$key]['count'] += 1;
			}
		}
		$resultT = $result->toArray();
		$resultT['group'] = $groups;
		$resultT['system_config'] = $system_config;
		return response()->json($resultT);
	}

	public function show(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
		$head = DB::select("
			SELECT b.emp_code, b.emp_name, b.working_start_date, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, b.chief_emp_code, b.has_second_line, e.emp_name chief_emp_name, s.emp_code second_chief_emp_code, s.emp_name second_chief_emp_name, c.appraisal_period_desc, a.appraisal_type_id, d.appraisal_type_name, a.stage_id, f.status, a.result_score, f.edit_flag, al.no_weight, a.position_id, a.org_id
			FROM emp_result a
			left outer join employee b
			on a.emp_id = b.emp_id
			left outer join appraisal_period c
			on c.period_id = a.period_id
			left outer join appraisal_type d
			on a.appraisal_type_id = d.appraisal_type_id
			left outer join employee e
			on b.chief_emp_code = e.emp_code
			left outer join employee s
			on e.chief_emp_code = s.emp_code
			left outer join appraisal_stage f
			on a.stage_id = f.stage_id
			left outer join position p
			on b.position_id = p.position_id
			left outer join org o
			on a.org_id = o.org_id
			left outer join org po
			on o.parent_org_code = po.org_code
			left outer join appraisal_level al
			on a.level_id = al.level_id
			where a.emp_result_id = ?
		", array($request->emp_result_id));

		if($head[0]->emp_code==Auth::id()) {
			$items = DB::select("
				select DISTINCT b.item_name,uom.uom_name, b.structure_id, c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, a.weigh_score, f.weigh_score total_weigh_score, a.weight_percent, g.weight_percent total_weight_percent, al.no_weight,
				if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, (select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount
					from appraisal_item_result a
				left outer join appraisal_item b
				on a.item_id = b.item_id
				left outer join appraisal_structure c
				on b.structure_id = c.structure_id
				left outer join form_type d
				on c.form_id = d.form_id
				left outer join perspective e
				on b.perspective_id = e.perspective_id
				left outer join structure_result f
				on a.emp_result_id = f.emp_result_id
				and c.structure_id = f.structure_id
				left outer join appraisal_criteria g
				on c.structure_id = g.structure_id
				and a.level_id = g.appraisal_level_id
				left outer join appraisal_level al
				on a.level_id = al.level_id
				left outer join emp_result h
				on a.emp_result_id = h.emp_result_id
				left join uom on  b.uom_id= uom.uom_id
				INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id 
				and ags.assessor_group_id = ?
				where a.emp_result_id = ?
				and d.form_id != 2
				order by c.seq_no, b.item_id
				", array($request->assessor_group_id, $request->emp_result_id));
		} else {
			$items = DB::select("
				select DISTINCT b.item_name,b.formula_desc,uom.uom_name, b.structure_id, c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, a.weigh_score, f.weigh_score total_weigh_score, a.weight_percent, g.weight_percent total_weight_percent, al.no_weight,
				if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, (select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount
					from appraisal_item_result a
				left outer join appraisal_item b
				on a.item_id = b.item_id
				INNER JOIN appraisal_item ai
				on ai.item_id = a.item_id
				left outer join appraisal_structure c
				on b.structure_id = c.structure_id
				left outer join form_type d
				on c.form_id = d.form_id
				left outer join perspective e
				on b.perspective_id = e.perspective_id
				left outer join structure_result f
				on a.emp_result_id = f.emp_result_id
				and c.structure_id = f.structure_id
				left outer join appraisal_criteria g
				on c.structure_id = g.structure_id
				and a.level_id = g.appraisal_level_id
				left outer join appraisal_level al
				on a.level_id = al.level_id
				left outer join emp_result h
				on a.emp_result_id = h.emp_result_id
				left join uom on  b.uom_id= uom.uom_id
				INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id 
				and ags.assessor_group_id = ?
				where a.emp_result_id = ?
				order by c.seq_no asc, b.item_id
				", array($request->assessor_group_id, $request->emp_result_id));
		}

		$groups = array();
		foreach ($items as $item) {
			$key = $item->structure_name;
			$color = DB::select("
				select color_code
				from result_threshold
				where ? between begin_threshold and end_threshold
				and result_threshold_group_id = ?
			", array($item->percent_achievement, $item->result_threshold_group_id));

			if (empty($color)) {
				$minmax = DB::select("
					select min(begin_threshold) min_threshold, max(end_threshold) max_threshold
					from result_threshold
					where result_threshold_group_id = ?
				",array($item->result_threshold_group_id));

				if (empty($minmax)) {
					$item->color = 0;
				} else {
					if ($item->percent_achievement < $minmax[0]->min_threshold) {
						$get_color = DB::select("
							select color_code
							from result_threshold
							where result_threshold_group_id = ?
							and begin_threshold = ?
						", array($item->result_threshold_group_id, $minmax[0]->min_threshold));
						$item->color = $get_color[0]->color_code;
					} elseif ($item->percent_achievement > $minmax[0]->max_threshold) {
						$get_color = DB::select("
							select color_code
							from result_threshold
							where result_threshold_group_id = ?
							and end_threshold = ?
						", array($item->result_threshold_group_id, $minmax[0]->max_threshold));
						$item->color = $get_color[0]->color_code;
					} else {
						$item->color = 0;
					}
				}
			} else {
				$item->color = $color[0]->color_code;
			}

			$hint = array();
			if ($item->form_id == 2) {
				$hint = DB::select("
					select concat(a.target_score,' = ',a.threshold_name) hint
					from threshold a
					left outer join threshold_group b
					on a.threshold_group_id = b.threshold_group_id
					where b.is_active = 1
					and a.structure_id=?
					order by target_score asc
				", array($item->structure_id));
			}

			/*
			$check = DB::select("
				select ifnull(max(a.end_threshold),0) max_no
				from result_threshold a left outer join result_threshold_group b
				on a.result_threshold_group_id = b.result_threshold_group_id
				where b.result_threshold_group_id = ?
				and b.result_type = 2
			", array($item->result_threshold_group_id));

			if ($check[0]->max_no == 0) {
				$total_weight = $item->structure_weight_percent;
			} else {
				$total_weight = ($check[0]->max_no * $item->structure_weight_percent) / 100;
			}

			*/

			$check = DB::select("
				SELECT nof_target_score as max_no FROM
			appraisal_structure
			where  structure_id=?
			", array($item->structure_id));

			$total_weight = ($check[0]->max_no * $item->structure_weight_percent) / 100;

			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'count' => 1,
					'form_id' => $item->form_id,
					'form_url' => $item->app_url,
					'is_value_get_zero' => $item->is_value_get_zero,
					'nof_target_score' => $item->nof_target_score,
					'total_weight' => $total_weight,
					'hint' => $hint,
					'total_weigh_score' => $item->total_weigh_score,
					'total_weight_percent' => $item->structure_weight_percent,
					'no_weight' => $item->no_weight,
					'threshold' => $config->threshold,
					'result_type' => $config->result_type
				);
			} else {
				$groups[$key]['items'][] = $item;
			//	$groups[$key]['total_weight'] += $item->weight_percent;
				$groups[$key]['count'] += 1;
			}
		}
	//	$resultT = $items->toArray();
	//	$items['group'] = $groups;

		$stage = DB::select("
			SELECT a.created_by, a.created_dttm, b.from_action, b.to_action, a.remark
			FROM emp_result_stage a
			left outer join appraisal_stage b
			on a.stage_id = b.stage_id
			where a.emp_result_id = ?
			order by a.created_dttm asc
		", array($request->emp_result_id));

		return response()->json(['head' => $head, 'data' => $items, 'group' => $groups, 'stage' => $stage]);

	}

	public function show_type2 (Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$auth = Auth::id();

		if($request->assessor_group_id == 5){
			$items = DB::select("
				select * from (
					select distinct com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id
					, ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id
					, com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero
					, CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name
					, com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id
					, com.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement
					, emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent
					, 0 as total_weigh_score
					, air.structure_weight_percent
					from competency_result com
					left outer join appraisal_level le on com.level_id = le.level_id
					left outer join appraisal_item ai on com.item_id = ai.item_id
					left outer join appraisal_structure aps on ai.structure_id = aps.structure_id
					left outer join form_type ft on aps.form_id = ft.form_id
					left outer join assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					left outer join employee em on com.assessor_id = em.emp_id
					left outer join appraisal_item_result air on com.item_result_id = air.item_result_id
					left outer join emp_result emp on air.emp_result_id = emp.emp_result_id
					left outer join structure_result f on emp.emp_result_id = f.emp_result_id
					left outer join appraisal_criteria g on aps.structure_id = g.structure_id
						and air.level_id = g.appraisal_level_id
					inner join assessor_group_structure ags on ags.structure_id = ai.structure_id
						and ags.assessor_group_id = 5
					where aps.form_id = 2
					and emp.emp_result_id = ?
					and 5 = ?
					union
					select DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url
					,0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, 0 as emp_id, 'ALL' as emp_code
					, 0 as assessor_group_id, 'ทั้งหมด' as assessor_group_name, '0' as assessor_id, c.is_value_get_zero, 'ทั้งหมด' as emp_name
					, a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, a.score, a.threshold_group_id
					, a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id
					, c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score
					, a.structure_weight_percent
					from appraisal_item_result a
					left outer join appraisal_item b
					on a.item_id = b.item_id
					INNER JOIN appraisal_item ai
					on ai.item_id = a.item_id
					left outer join appraisal_structure c
					on b.structure_id = c.structure_id
					left outer join form_type d
					on c.form_id = d.form_id
					left outer join perspective e
					on b.perspective_id = e.perspective_id
					left outer join structure_result f
					on a.emp_result_id = f.emp_result_id
					and c.structure_id = f.structure_id
					left outer join appraisal_criteria g
					on c.structure_id = g.structure_id
					and a.level_id = g.appraisal_level_id
					left outer join appraisal_level al
					on a.level_id = al.level_id
					left outer join emp_result h
					on a.emp_result_id = h.emp_result_id
					left join uom on  b.uom_id= uom.uom_id
					inner join assessor_group_structure ags on ags.structure_id = b.structure_id
							and ags.assessor_group_id = ?
					where a.emp_result_id = ?
					and c.form_id = 2
				) re 
				order by re.structure_id asc, re.assessor_group_id asc, re.assessor_id asc,  re.item_id asc"
					,array($request->emp_result_id, $request->assessor_group_id, $request->assessor_group_id, $request->emp_result_id));
		}else {
			$check = DB::select("select count(competency_result_id) as num
				from competency_result c
				inner join appraisal_item_result i on c.item_result_id = i.item_result_id
				inner join employee e on c.assessor_id = e.emp_id
				where i.emp_result_id = ? 
				and assessor_group_id = ?
				and e.emp_code = '{$auth}' "
			,array($request->emp_result_id, $request->assessor_group_id));

			if($check[0]->num == 0){
				$items = DB::select("
					select DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url
					,0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, 0 as emp_id, 'ALL' as emp_code
					, 0 as assessor_group_id, 'ทั้งหมด' as assessor_group_name, '0' as assessor_id, c.is_value_get_zero, 'ทั้งหมด' as emp_name
					, a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id
					, a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id
					, c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score
					, a.structure_weight_percent
					from appraisal_item_result a
					left outer join appraisal_item b
					on a.item_id = b.item_id
					INNER JOIN appraisal_item ai
					on ai.item_id = a.item_id
					left outer join appraisal_structure c
					on b.structure_id = c.structure_id
					left outer join form_type d
					on c.form_id = d.form_id
					left outer join perspective e
					on b.perspective_id = e.perspective_id
					left outer join structure_result f
					on a.emp_result_id = f.emp_result_id
					and c.structure_id = f.structure_id
					left outer join appraisal_criteria g
					on c.structure_id = g.structure_id
					and a.level_id = g.appraisal_level_id
					left outer join appraisal_level al
					on a.level_id = al.level_id
					left outer join emp_result h
					on a.emp_result_id = h.emp_result_id
					left join uom on  b.uom_id= uom.uom_id
					inner join assessor_group_structure ags on ags.structure_id = b.structure_id
							and ags.assessor_group_id = ?
					where a.emp_result_id = ?
					and c.form_id = 2
					order by b.item_id asc"
				,array($request->assessor_group_id, $request->emp_result_id));
			}else {
				$items = DB::select("
					select distinct com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id
					, ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id
					, com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero
					, CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name
					, com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id
					, com.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, air.structure_weight_percent
					, emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent
					-- , f.weigh_score as total_weigh_score
					from competency_result com
					left outer join appraisal_level le on com.level_id = le.level_id
					left outer join appraisal_item ai on com.item_id = ai.item_id
					left outer join appraisal_structure aps on ai.structure_id = aps.structure_id
					left outer join form_type ft on aps.form_id = ft.form_id
					left outer join assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					left outer join employee em on com.assessor_id = em.emp_id
					left outer join appraisal_item_result air on com.item_result_id = air.item_result_id
					left outer join emp_result emp on air.emp_result_id = emp.emp_result_id
					left outer join structure_result f on emp.emp_result_id = f.emp_result_id
					left outer join appraisal_criteria g on aps.structure_id = g.structure_id
						and air.level_id = g.appraisal_level_id
					inner join assessor_group_structure ags on ags.structure_id = ai.structure_id
						and ags.assessor_group_id = ?
					where aps.form_id = 2
					and emp.emp_result_id = ?
					and em.emp_code = '{$auth}'
					order by ai.structure_id asc,com.assessor_group_id asc, com.assessor_id asc, com.item_id asc"
				,array($request->assessor_group_id, $request->emp_result_id));
			}
		}

		$groups = array();
		foreach($items as $item){

			$hint = array();
			
			if ($item->form_id == 2) {
				$hint = DB::select("
					select concat(a.target_score,' = ',a.threshold_name) hint
					from threshold a
					left outer join threshold_group b
					on a.threshold_group_id = b.threshold_group_id
					where b.is_active = 1
					and a.structure_id=?
					order by target_score asc
				", array($item->structure_id));
			}

			$key = $item->structure_name;
			$k = $item->assessor_group_name;
			$emp = $item->emp_name;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'count' => 1,
					'form_id' => $item->form_id,
					'form_url' => $item->app_url,
					'structure_name' => $item->structure_name,
					'structure_id' => $item->structure_id,
					'hint' => $hint,
				);
				//in $key
				if (!isset($groups[$key][$k])) {
					$groups[$key][$k] = array(
						'group_name' => $item->assessor_group_name,
						'group_id' => $item->assessor_group_id,
						// 'total_weigh_score' => $item->total_weigh_score,
						// 'total_weight_percent' => $item->total_weight_percent,
					);
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'total_weight_percent' => $item->total_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}else {
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'total_weight_percent' => $item->total_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}
			} else {
				$groups[$key]['count'] += 1;
				//in $key
				if (!isset($groups[$key][$k])) {
					$groups[$key][$k] = array(
						'group_name' => $item->assessor_group_name,
						'group_id' => $item->assessor_group_id,
						'total_weigh_score' => $item->total_weigh_score,
					);
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'total_weight_percent' => $item->total_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}else {
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'total_weight_percent' => $item->total_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}
			}

			// $key = $item->structure_name;
			// if (!isset($groups[$key])) {
			// 	$groups[$key] = array(
			// 		'items' => array($item),
			// 		'count' => 1,
			// 		'form_id' => $item->form_id,
			// 		'form_url' => $item->app_url,
			// 		'structure_name' => $item->structure_name,
			// 		'group_name' => $item->assessor_group_name,
			// 		'group_id' => $item->assessor_group_id,
			// 		'hint' => $hint,
			// 		'total_weigh_score' => $item->total_weigh_score,
			// 	);
			// } else {
			// 	$groups[$key]['items'][] = $item;
			// 	$groups[$key]['count'] += 1;
			// }
		}

		return response()->json($groups);
	}

	
	public function update(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$now = new DateTime();
		$datas = json_decode($request->data);
		$auth = "seekpi003"; //Auth::id();

		$item_result = DB::select("
				select item_result_id
				, period_id
				, emp_id
				, org_id
				, position_id
				, item_id
				, level_id
				, item_name
				, chief_emp_id
				, threshold_group_id
				, auth_emp_id
				from appraisal_item_result 
				cross join (select emp_id as auth_emp_id from employee where emp_code = '{$auth}') emp 
				where item_result_id = ? "
		,array($datas[0]->item_result_id));
			// return response()->json($item_result);

		foreach ($datas as $da){
		
			foreach ($item_result as $item){
				if($da->group_id == 0){

					// update to app..item_result
					$item_result = AppraisalItemResult::find($da->item_result_id);
					$item_result->score = $da->score;
					$item_result->updated_by = $auth;
					$item_result->updated_dttm = $now;
					$item_result->save();

				}else {
					$competency = CompetencyResult::find($da->competency_result_id);
					if(empty($competency)){
					
						$competency = new CompetencyResult;
						$competency->item_result_id = $item->item_result_id;
						$competency->period_id = $item->period_id;
						$competency->emp_id = $item->emp_id;
						$competency->org_id = $item->org_id;
						$competency->position_id = $item->position_id;
						$competency->item_id = $item->item_id;
						$competency->level_id = $item->level_id;
						$competency->item_name = $item->item_name;
						$competency->chief_emp_id = $item->chief_emp_id;
						$competency->assessor_id = $item->auth_emp_id;
						$competency->threshold_group_id = $item->threshold_group_id;
						$competency->assessor_group_id = $da->group_id;
						$competency->target_value = $da->target_value;
						$competency->score = $da->score;
						$competency->weight_percent = $da->weight_percent;
						$competency->group_weight_percent = 0 ;
						$competency->weigh_score = $da->weigh_score;
						$competency->created_by = $auth;
						$competency->created_dttm = $now;
						$competency->save();
					}
					$competency->score = $da->score;
					$competency->updated_by = $auth;
					$competency->updated_dttm = $now;
					$competency->save();
				}
			}//end for item
		}//end for datas

		
		return response()->json(['status' => 200]);
	}

}

?>
