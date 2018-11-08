<?php

namespace App\Http\Controllers;

use App\SystemConfiguration;

use PDO;
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

class ReportController extends Controller
{

	public function __construct()
	{
		//$this->middleware('jwt.auth');
	}
	
    public function al_list()
    {
		$items = DB::select("
			Select level_id, appraisal_level_name
			From appraisal_level 
			Where is_active = 1 
			order by appraisal_level_name
		");
		return response()->json($items);
    }

    public function al_list_emp()
    {
    	$items = DB::select("
    		Select level_id, appraisal_level_name
    		From appraisal_level
    		Where is_active = 1
    		and is_individual = 1 
    		Order by level_id desc
    	");
		return response()->json($items);
    }

    public function al_list_org(Request $request)
    {
    	if(empty($request->level_id) || $request->level_id == 'All') {
    	$items = DB::select("
				Select level_id, appraisal_level_name
				From appraisal_level
				Where is_active = 1
				and is_org = 1
				Order by level_id
			");
    	} else {
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
    	}
    	return response()->json($items);
    }

    public function org_list(Request $request)
    {
    	if(empty($request->appraisal_level) || $request->appraisal_level =='All') {
    		$items = DB::select("
			SELECT org_id, org_name
			FROM org
			WHERE is_active = 1
			ORDER BY org_id
		");
    	} else {
    		$items = DB::select("
			SELECT org_id, org_name
			FROM org
			WHERE is_active = 1
			AND level_id = ?
			ORDER BY org_id
		", array($request->appraisal_level));
    	}
		return response()->json($items);
    }

    public function status_list(Request $request)
    {
    	$items = DB::select("
    		select DISTINCT to_action, to_action 
    		from appraisal_stage
    		where appraisal_type_id = ?
    		order by to_action asc
		", array($request->appraisal_type_id));
		return response()->json($items);
    }

    public function emp_list_level(Request $request)
    {
    	$items = DB::select("
			select al.level_id, al.appraisal_level_name
			from appraisal_level al
			inner join employee e
			on al.level_id = e.level_id
			inner join usage_log ul
			on e.emp_code = ul.emp_code
			where al.is_active = 1
			group by al.level_id
			order by al.seq_no ASC
		");
		return response()->json($items);
    }

    public function org_list_individual(Request $request)
    {
    	$level_id = empty($request->level_id) ? "" : "where e.level_id = {$request->level_id}";

    	$items = DB::select("
			select DISTINCT o.level_id, al.appraisal_level_name
			from org o
			inner join appraisal_level al
			on o.level_id = al.level_id
			inner join employee e
			on o.org_id = e.org_id
			inner join usage_log ul
			on e.emp_code = ul.emp_code
			".$level_id."
			order by al.seq_no ASC
			");
		return response()->json($items);
    }

    public function org_individual(Request $request)
    {
    	$emp_level = empty($request->emp_level) ? "" : "and e.level_id = {$request->emp_level}";
    	$org_level = empty($request->org_level) ? "" : "and o.level_id = {$request->org_level}";

    	$items = DB::select("
    		select o.org_id, o.org_name
    		from org o
    		inner join employee e
    		on e.org_id = o.org_id
    		inner join usage_log ul
			on e.emp_code = ul.emp_code
    		where 1=1
    		".$emp_level."
    		".$org_level."
    		group by o.org_id
    		order by o.org_name asc
    	");
		return response()->json($items);
    }
	
	public function usage_log(Request $request) 
	{

		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;
		
		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		// Start displaying items from this number;
		$offset = ($page * $perPage) - $perPage; // Start displaying items from this number		
			
		$limit = " limit " . $perPage . " offset " . $offset;
		
		// $query ="			
		// 	select SQL_CALC_FOUND_ROWS a.created_dttm, b.emp_code, b.emp_name, d.org_name, e.appraisal_level_name, c.friendlyURL url
		// 	from usage_log a,
		// 	employee b,
		// 	lportal.Layout c,
		// 	org d,
		// 	appraisal_level e
		// 	where a.emp_code = b.emp_code
		// 	and a.plid = c.plid
		// 	and b.org_id = d.org_id
		// 	and b.level_id = e.level_id
		// ";

		$query ="			
			select SQL_CALC_FOUND_ROWS a.created_dttm, b.emp_code, b.emp_name, d.org_name, e.appraisal_level_name, c.friendlyURL url
			from usage_log a
			inner join employee b on a.emp_code = b.emp_code
			inner join lportal.Layout c on a.plid = c.plid
			left join org d on b.org_id = d.org_id
			left join appraisal_level e on b.level_id = e.level_id
			where 1=1
		";
			
		$qfooter = " order by e.appraisal_level_name asc, a.created_dttm desc, a.emp_code asc, url asc " . $limit;		
		$qinput = array();
		
		// empty($request->branch_code) ?: ($query .= " and b.branch_code = ? " AND $qinput[] =  $request->branch_code);
		// empty($request->personnel_name) ?: ($query .= " and b.thai_full_name like ? " AND  $qinput[] = '%' . $request->personnel_name . '%');
		if (!empty($request->usage_start_date) and empty($request->usage_end_date)) {
			$query .= " and date(a.created_dttm) >= date(?) ";
			$qinput[] = $request->usage_start_date;		
		} elseif (empty($request->usage_start_date) and empty($request->usage_end_date)) {
		} else {
			$query .= " and date(a.created_dttm) between date(?) and date(?) ";
			$qinput[] = $request->usage_start_date;
			$qinput[] = $request->usage_end_date;				
		}
		empty($request->emp_id) ?: ($query .= " and b.emp_id = ? " AND $qinput[] = $request->emp_id);
		empty($request->position_id) ?: ($query .= " and b.position_id = ? " AND $qinput[] = $request->position_id);
		empty($request->level_id) ?: ($query .= " and b.level_id = ? " AND $qinput[] = $request->level_id);
		empty($request->level_id_org) ?: ($query .= " and d.level_id = ? " AND $qinput[] = $request->level_id_org);
		empty($request->org_id) ?: ($query .= " and b.org_id = ? " AND $qinput[] = $request->org_id);
	
		$items = DB::select($query . $qfooter, $qinput);
		$count = DB::select("select found_rows() as total_count");

	
		$groups = array();
		foreach ($items as $item) {

			$key = ($request->appraisal_type==1) ? $item->org_name : $item->appraisal_level_name;

			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'count' => 1,
				);
			} else {
				$groups[$key]['items'][] = $item;
				$groups[$key]['count'] += 1;
			}
		}		
		
		empty($items) ? $totalPage = 0 : $totalPage = $count[0]->total_count;
		
		$result = [
			"total" => $totalPage, 
			"current_page" => $page,
			"last_page" => ceil($totalPage / $perPage),
			"data" => $groups
		];
		
		return response()->json($result);	
	}
}