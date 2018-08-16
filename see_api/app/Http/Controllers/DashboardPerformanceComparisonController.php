<?php

namespace App\Http\Controllers;

use App\Org;
use App\AppraisalItem;
use App\Perspective;
use App\AppraisalPeriod;
use App\AppraisalFrequency;
use App\Employee;
use App\UOM;
use App\SystemConfiguration;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use DateTime;
use DateInterval;
use DatePeriod;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DashboardPerformanceComparisonController extends Controller
{

	public function __construct()
	{

	   $this->middleware('jwt.auth');
	}


	public function position_list(Request $request)
	{
		$emp_id = $request->emp_id;

		$items = DB::select("
			SELECT po.position_id, po.position_name
			FROM employee emp
			INNER JOIN position po ON emp.position_id = po.position_id
			WHERE emp.emp_id = {$emp_id}
		");

		return response()->json($items);
	}


	public function bar_chart(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		//check data in table
		$query_check = "select count(structure_result_id) as num
				from structure_result sr
				inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
				inner join appraisal_period ap on sr.period_id = ap.period_id
				where emp.org_id = ?
				and emp.level_id = ?
				and emp.appraisal_type_id = ?
				and ap.appraisal_year = ?";

		if($request->appraisal_type_id == 2){
			$check = DB::select($query_check."
			and sr.emp_id = ?
			and emp.position_id = ?"
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year
			, $request->emp_id, $request->position_id));
		}
		if($request->appraisal_type_id == 1){
			$check = DB::select($query_check
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year));
		}

		if($check[0]->num == 0){
			return response()->json(['status' => 400, 'data' => ' Information not found.']);
		}
		//end check data in table

		$year_old = "
			select max(ap.appraisal_year) as year_old
			from emp_result emp
			inner join appraisal_item_result air on emp.emp_result_id = air.emp_result_id
			inner join appraisal_period ap on air.period_id = ap.period_id
			where ap.appraisal_year < ?";


		if(($request->appraisal_type_id) == 2){
			//Individual

			$value_old = "
				select em.emp_id
				, em.emp_name
				, (case when round(avg(sr.weigh_score),2) = 0 then null else round(avg(sr.weigh_score),2) end) as result_score_old
				-- , round(avg(sr.weigh_score),2) as result_score_old
				from structure_result sr
				inner join appraisal_structure aps on sr.structure_id = aps.structure_id
				inner join appraisal_period ap on sr.period_id = ap.period_id
				inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
				inner join (select distinct level_id, position_id, org_id, appraisal_type_id
						from emp_result
						where emp_id = ?) lev ON lev.level_id = emp.level_id  -- emp_id
						and lev.position_id = emp.position_id
						and lev.org_id = emp.org_id
				inner join employee em on sr.emp_id = em.emp_id
				where aps.form_id = 1
				and ap.appraisal_year = (".$year_old.")
				and emp.level_id = ? -- level_id
				and emp.org_id = ? -- org_id
				and emp.position_id = ? -- position_id
				and lev.appraisal_type_id = ? -- appraisal_type_id
				group by em.emp_id";

			$value = DB::select("
				SELECT result.emp_id as id
				, result.emp_name as name
				, result.result_score as total
				, (case when result.`change` > 0 then concat('+',COALESCE(round(result.`change`,2),0))
					when result.`change` < 0 then concat('-',COALESCE(round(result.`change`,2),0))
					else COALESCE(round(result.`change`,2),0) end) AS yoy_name
				, COALESCE(round(result.`change`,2),0) as yoy
				FROM (
					select em.emp_id
					, em.emp_name
					, round(avg(sr.weigh_score),2) as result_score
					, ((ROUND(avg(sr.weigh_score),2)-old.result_score_old)
						/old.result_score_old)*100 as `change`
					, old.result_score_old
					from structure_result sr
					inner join appraisal_structure aps on sr.structure_id = aps.structure_id
					inner join appraisal_period ap on sr.period_id = ap.period_id
					inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
					inner join (select distinct level_id, position_id, org_id, appraisal_type_id
							from emp_result
							where emp_id = ?) lev ON lev.level_id = emp.level_id  -- emp_id
							and lev.position_id = emp.position_id
							and lev.org_id = emp.org_id
					inner join employee em on sr.emp_id = em.emp_id
					left join (".$value_old."
					)old on old.emp_id = em.emp_id
					where aps.form_id = 1
					and ap.appraisal_year = ?
					and emp.level_id = ? -- level_id
					and emp.org_id = ? -- org_id
					and emp.position_id = ? -- position_id
					and lev.appraisal_type_id = ? -- appraisal_type_id
					group by em.emp_id
				)result
				ORDER BY result.result_score DESC"
			,array($request->emp_id, $request->emp_id, $request->appraisal_year, $request->level_id, $request->org_id
			, $request->position_id, $request->appraisal_type_id, $request->appraisal_year, $request->level_id
			, $request->org_id, $request->position_id, $request->appraisal_type_id));
		}

		if(($request->appraisal_type_id) == 1){
			//Organization

			$value_old = "
			select org.org_id
			, org.org_name
			, (case when round(avg(sr.weigh_score),2) = 0 then null else round(avg(sr.weigh_score),2) end) as result_score_old
			-- , round(avg(sr.weigh_score),2) as result_score_old
			from structure_result sr
			inner join appraisal_structure aps on sr.structure_id = aps.structure_id
			inner join appraisal_period ap on sr.period_id = ap.period_id
			inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
			inner join (select distinct level_id, org_id, appraisal_type_id
			from emp_result
			where org_id = ?) lev ON lev.level_id = emp.level_id  -- org_id
			inner join org on emp.org_id = org.org_id
			where aps.form_id = 1
			and ap.appraisal_year = (".$year_old.") -- appraisal_year
			and emp.level_id = ? -- level_id
			and emp.appraisal_type_id = ? -- appraisal_type_id
			group by org.org_id";

			$value = DB::select("
				SELECT result.org_id as id
				, result.org_name as name
				, result.result_score as total
				, (case when result.`change` > 0 then concat('+',COALESCE(round(result.`change`,2),0))
					when result.`change` < 0 then concat('-',COALESCE(round(result.`change`,2),0))
					else COALESCE(round(result.`change`,2),0) end) AS yoy_name
				, COALESCE(round(result.`change`,2),0) as yoy
				FROM (
					select org.org_id
					, org.org_name
					, round(avg(sr.weigh_score),2) as result_score
					, ((ROUND(avg(sr.weigh_score),2)-old.result_score_old)
						/old.result_score_old)*100 as `change`
					, old.result_score_old
					from structure_result sr
					inner join appraisal_structure aps on sr.structure_id = aps.structure_id
					inner join appraisal_period ap on sr.period_id = ap.period_id
					inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
					inner join (select distinct level_id, org_id, appraisal_type_id
						from emp_result
						where org_id = ?) lev ON lev.level_id = emp.level_id  -- org_id
					inner join org on emp.org_id = org.org_id
					left join (".$value_old."
					)old on old.org_id = org.org_id
					where aps.form_id = 1
					and ap.appraisal_year = ? -- appraisal_year
					and emp.level_id = ? -- level_id
					and emp.appraisal_type_id = ? -- appraisal_type_id
					group by org.org_id
				)result
				ORDER BY result.result_score DESC"
			,array($request->org_id, $request->org_id, $request->appraisal_year, $request->level_id
			, $request->appraisal_type_id, $request->appraisal_year, $request->level_id, $request->appraisal_type_id));
		}

		return response()->json($value);
	}


	public function line_chart(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		//check data in table
		$query_check = "select count(structure_result_id) as num
				from structure_result sr
				inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
				inner join appraisal_period ap on sr.period_id = ap.period_id
				where emp.org_id = ?
				and emp.level_id = ?
				and emp.appraisal_type_id = ?
				and ap.appraisal_year = ?";

		if($request->appraisal_type_id == 2){
			$check = DB::select($query_check."
			and sr.emp_id = ?
			and emp.position_id = ?"
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year
			, $request->emp_id, $request->position_id));
		}
		if($request->appraisal_type_id == 1){
			$check = DB::select($query_check
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year));
		}

		if($check[0]->num == 0){
			return response()->json(['status' => 400, 'data' => ' Information not found.']);
		}
		//end check data in table


		//appraisal_year
		$sql_year = DB::select("
				select result.appraisal_year
				from (
					select ap.appraisal_year
					from structure_result sr
					inner join appraisal_period ap on sr.period_id = ap.period_id
					where ap.appraisal_year <= ?
					group by ap.appraisal_year
					order by ap.appraisal_year desc
					limit 6
				)result
				order by result.appraisal_year asc"
		,array( $request->appraisal_year));

		if($request->appraisal_type_id == 2){
			//Individual
			foreach ($sql_year as $year) {
				$sql_value = DB::select("
					select em.emp_id as id
					, em.emp_name as name
					, round(avg(sr.weigh_score),2) as total
					from structure_result sr
					inner join appraisal_structure aps on sr.structure_id = aps.structure_id
					inner join appraisal_period ap on sr.period_id = ap.period_id
					inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
					inner join employee em on sr.emp_id = em.emp_id
					where aps.form_id = 1
					and ap.appraisal_year = ? -- appraisal_year
					and emp.appraisal_type_id = ? -- appraisal_type_id
					and emp.level_id = ? -- level_id
					and emp.org_id = ? -- org_id
					and emp.position_id = ? -- position_id
					and emp.emp_id = ? -- emp_id
					group by em.emp_id"
					, array($year->appraisal_year, $request->appraisal_type_id, $request->level_id
				, $request->org_id, $request->position_id, $request->emp_id));

				//query average value
				$sql_max_min_avg = "
					select round(avg(sr.weigh_score),2) as total
					-- em.emp_id, em.emp_name
					from structure_result sr
					inner join appraisal_structure aps on sr.structure_id = aps.structure_id
					inner join appraisal_period ap on sr.period_id = ap.period_id
					inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
					inner join (select distinct level_id, position_id, org_id, appraisal_type_id
						from emp_result
						where emp_id = ?) lev ON lev.level_id = emp.level_id  -- emp_id
						and lev.position_id = emp.position_id
						and lev.org_id = emp.org_id
					inner join employee em on sr.emp_id = em.emp_id
					where aps.form_id = 1
					and ap.appraisal_year = ? -- appraisal_year
					and emp.appraisal_type_id = ? -- appraisal_type_id
					and emp.level_id = ? -- level_id
					and emp.org_id = ? -- org_id
					and emp.position_id = ? -- position_id
				";

				//max value
				$sql_max = $sql_max_min_avg."
					group by em.emp_id
					ORDER BY ROUND(AVG(emp.result_score),2) DESC
					LIMIT 1
				";
				$sql_max = DB::select($sql_max, array($request->emp_id, $year->appraisal_year, $request->appraisal_type_id
				, $request->level_id, $request->org_id, $request->position_id));

				//min value
				$sql_min = $sql_max_min_avg."
					group by em.emp_id
					ORDER BY ROUND(AVG(emp.result_score),2) ASC
					LIMIT 1
				";
				$sql_min = DB::select($sql_min, array($request->emp_id, $year->appraisal_year, $request->appraisal_type_id
				, $request->level_id, $request->org_id, $request->position_id));

				//average value
				$sql_avg = DB::select($sql_max_min_avg, array($request->emp_id, $year->appraisal_year, $request->appraisal_type_id
				, $request->level_id, $request->org_id, $request->position_id));

				//change result query to array
				$sql_value = collect($sql_value)->toArray();
				$sql_max = collect($sql_max)->toArray();
				$sql_min = collect($sql_min)->toArray();
				$sql_avg = collect($sql_avg)->toArray();

				//value in $sql_value to $sql_year
				foreach ($sql_value as $key => $value) {
					$year->name = $value->name;
					$year->id = $value->id;
					$year->value = $value->total;
				}

				//value in $sql_max to $sql_year
				foreach ($sql_max as $key => $value_max) {
					$year->top = $value_max->total;
				}

				//value in $sql_min to $sql_year
				foreach ($sql_min as $key => $value_min) {
					$year->bottom = $value_min->total;
				}

				//value in $sql_avg to $sql_year
				foreach ($sql_avg as $key => $value_avg) {
					$year->avg = $value_avg->total;
				}
			}//end for
			// return response()->json($sql_year);
			return $this->change_view($sql_year); //send to function change_view
		}//end if

		if($request->appraisal_type_id == 1){
			//Organization
			foreach ($sql_year as $year) {
				$sql_value = DB::select("
					select org.org_id as id
					, org.org_name as name
					, round(avg(sr.weigh_score),2) as total
					from structure_result sr
					inner join appraisal_structure aps on sr.structure_id = aps.structure_id
					inner join appraisal_period ap on sr.period_id = ap.period_id
					inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
					inner join org on emp.org_id = org.org_id
					where aps.form_id = 1
					and ap.appraisal_year = ? -- appraisal_year
					and emp.appraisal_type_id = ? -- appraisal_type_id
					and emp.level_id = ? -- level_id
					and emp.org_id = ? -- org_id
					group by org.org_id"
					, array($year->appraisal_year, $request->appraisal_type_id, $request->level_id, $request->org_id));

					//query average value
					$sql_max_min_avg = "
						select round(avg(sr.weigh_score),2) as total
						-- org.org_id, org.org_name
						from structure_result sr
						inner join appraisal_structure aps on sr.structure_id = aps.structure_id
						inner join appraisal_period ap on sr.period_id = ap.period_id
						inner join emp_result emp on sr.emp_result_id = emp.emp_result_id
						inner join (select distinct level_id, org_id, appraisal_type_id
							from emp_result
							where org_id = ?) lev ON lev.level_id = emp.level_id  -- org_id
						inner join org on emp.org_id = org.org_id
						where aps.form_id = 1
						and ap.appraisal_year = ? -- appraisal_year
						and emp.appraisal_type_id = ? -- appraisal_type_id
						and emp.level_id = ? -- level_id
					";

					//max value
					$sql_max = $sql_max_min_avg."
						group by org.org_id
						ORDER BY ROUND(AVG(emp.result_score),2) DESC
						LIMIT 1";

					$sql_max = DB::select($sql_max, array($request->org_id, $year->appraisal_year
					, $request->appraisal_type_id, $request->level_id));

					//min value
					$sql_min = $sql_max_min_avg."
						group by org.org_id
						ORDER BY ROUND(AVG(emp.result_score),2) ASC
						LIMIT 1";

					$sql_min = DB::select($sql_min, array($request->org_id, $year->appraisal_year
					, $request->appraisal_type_id, $request->level_id));

					//average value
					$sql_avg = DB::select($sql_max_min_avg, array($request->org_id, $year->appraisal_year
					, $request->appraisal_type_id, $request->level_id));

					//change result query to array
					$sql_value = collect($sql_value)->toArray();
					$sql_max = collect($sql_max)->toArray();
					$sql_min = collect($sql_min)->toArray();
					$sql_avg = collect($sql_avg)->toArray();

					//value in $sql_value to $sql_year
					foreach ($sql_value as $key => $value) {
						$year->name = $value->name;
						$year->id = $value->id;
						$year->value = $value->total;
					}

					//value in $sql_max to $sql_year
					foreach ($sql_max as $key => $value_max) {
						$year->top = $value_max->total;
					}

					//value in $sql_min to $sql_year
					foreach ($sql_min as $key => $value_min) {
						$year->bottom = $value_min->total;
					}

					//value in $sql_avg to $sql_year
					foreach ($sql_avg as $key => $value_avg) {
						$year->avg = $value_avg->total;
					}
			}//end for
			//return response()->json($sql_period);
			return $this->change_view($sql_year);  //send to function change_view
		}//end if

	}

	public function change_view($query){
		$categories_table = array();
		$categories_chart = array();
		$dataset = array();

		$category_table = array();
		$category_chart = array();

    //data to dataset (array)
		$data = array();
		$data_top = array();
		$data_avg = array();
		$data_bottom = array();

		foreach ($query as $sql) {
			$category_table[] = ['label' => $sql->appraisal_year, 'id' => $sql->appraisal_year];		//name and id period for category
			$category_chart[] = ['label' => $sql->appraisal_year];
			$data[] = ['value' => $sql->value];  						//data series1
			$name = $sql->name; 														//name series1
			$data_top[] = ['value' => $sql->top];						//data series top
			$data_avg[] = ['value' => $sql->avg];						//data series avg
			$data_bottom[] = ['value' => $sql->bottom];			//data series avg

		}

		//category to calegories
		$categories_table[] = ['category' => $category_table];
		$categories_chart[] = ['category' => $category_chart];

		//set data and name series to dataset
		$dataset[] = ['seriesname' => $name,'data' => $data];
		$dataset[] = ['seriesname' => 'Top','data' => $data_top];
		$dataset[] = ['seriesname' => 'Avg','data' => $data_avg];
		$dataset[] = ['seriesname' => 'Bottom','data' => $data_bottom];

		$result = ['categories_table' => $categories_table,
							'categories_chart' => $categories_chart,
							'dataset' => $dataset];

		return response()->json($result);
	}

	public function table_structure(Request $request){

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		//check data in table
		$query_check = "select count(emp.emp_result_id) as num
				from emp_result emp
				inner join appraisal_period ap on emp.period_id = ap.period_id
				where emp.org_id = ?
				and emp.level_id = ?
				and emp.appraisal_type_id = ?
				and ap.appraisal_year = ?";

		if($request->appraisal_type_id == 2){
			$check = DB::select($query_check."
				and emp.emp_id = ?
				and emp.position_id = ?"
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year
			, $request->emp_id, $request->position_id));
		}
		if($request->appraisal_type_id == 1){
			$check = DB::select($query_check
			,array( $request->org_id, $request->level_id, $request->appraisal_type_id, $request->appraisal_year));
		}

		if($check[0]->num == 0){
			return response()->json(['status' => 400, 'data' => ' Information not found.']);
		}
		//end check data in table


		$system_config = DB::select("select threshold from system_config");			//select threshold

		if($request->appraisal_type_id == 2){
			//Individual

				$sql_from1 = "
					select aps.structure_name
					, aps.structure_id
					, per.perspective_id
					, per.perspective_name
					, ai.item_id
					, ai.item_name
					, em.emp_name
					, ROUND(count(air.period_id),2) as count_period
					, ROUND(avg(air.target_value),2) as target_value
					, uom.uom_name
					, ROUND(avg(air.actual_value),2) as actual_value
					, (case when 1={$system_config[0]->threshold} then 'Score' else '% Achievement' end) as name_score_achievement  -- threshold
					, (case when 1={$system_config[0]->threshold} then ROUND(avg(air.score),2)
						else ROUND(avg(air.percent_achievement),2) end) as score_achievement   -- threshold
					, ROUND(avg(air.weight_percent),2) as weight_percent -- /avg
					, (case when 1={$system_config[0]->threshold} then ROUND((avg(air.score)*avg(air.weight_percent)),2)  -- threshold
						else ROUND((avg(air.percent_achievement)*avg(air.weight_percent))/100,2)
						end) as weight_score
					, (case when 1={$system_config[0]->threshold} then aps.nof_target_score  -- threshold
					 	else (select avg(count_of_item) from structure_result where emp_result_id = air.emp_result_id
				 		and structure_id = aps.structure_id ) end) as divider  -- period_id
					, aps.form_id
					-- from appraisal_item_result air
					from emp_result e
					inner join appraisal_item_result air on air.emp_result_id = e.emp_result_id
					inner join appraisal_item ai on air.item_id = ai.item_id
					inner join perspective per on ai.perspective_id = per.perspective_id
					inner join appraisal_structure aps on ai.structure_id = aps.structure_id
					inner join appraisal_period pe on air.period_id = pe.period_id
					left join uom on ai.uom_id = uom.uom_id
					inner join employee em on air.emp_id = em.emp_id
					where e.emp_id = ?  -- emp_id
					and e.level_id = ?  -- level_id
					and e.org_id = ?  -- org_id
					and e.position_id = ?  -- position_id
					and pe.appraisal_year = ? -- appraisal_year
					and aps.form_id = 1
					and e.appraisal_type_id = 2
					group by aps.structure_name
					, aps.structure_id
					, per.perspective_id
					, per.perspective_name
					, ai.item_id
					, ai.item_name
					order by ai.structure_id asc, per.perspective_id asc, ai.item_name asc";
				// ,array($request->period_id, $request->period_id, $request->emp_id, $request->level_id, $request->org_id, $request->position_id
				// , $request->period_id, $request->emp_id, $request->level_id, $request->org_id, $request->position_id));

				$sql_detail = "
					select aps.structure_name
					, aps.structure_id
					, ai.item_id
					, ai.item_name
					, pe.period_id
					, pe.appraisal_period_desc
					, air.target_value
					, uom.uom_name
					, air.actual_value
					, (case when 1={$system_config[0]->threshold} then 'Score' else '% Achievement' end) as name_score_achievement  -- threshold
					, (case when 1={$system_config[0]->threshold} then air.score
						else air.percent_achievement end) as score_achievement   -- threshold
					, air.weight_percent
					, (case when 1={$system_config[0]->threshold} then (air.score)*(air.weight_percent)  -- threshold
						else (air.percent_achievement)*(air.weight_percent)
						end) as weight_score
					/* , (case when 1={$system_config[0]->threshold} then aps.nof_target_score  -- threshold
					 	else (select count_of_item from structure_result where emp_id = em.emp_id
					 	and structure_id = aps.structure_id and period_id = pe.period_id) end) as divider */  -- period_id
					, aps.form_id
					-- from appraisal_item_result air
					from emp_result e
					inner join appraisal_item_result air on air.emp_result_id = e.emp_result_id
					inner join appraisal_item ai on air.item_id = ai.item_id
					inner join appraisal_structure aps on ai.structure_id = aps.structure_id
					inner join appraisal_period pe on air.period_id = pe.period_id
					left join uom on ai.uom_id = uom.uom_id
					inner join employee em on air.emp_id = em.emp_id
					where e.emp_id = ?  -- emp_id
					and e.level_id = ?  -- level_id
					and e.org_id = ?  -- org_id
					and e.position_id = ?  -- position_id
					and pe.appraisal_year = ? -- appraisal_year
					and aps.form_id = 1
					and ai.item_id = ?
					and e.appraisal_type_id = 2
					order by pe.period_id asc";

			$sql_one = DB::select($sql_from1,array( $request->emp_id, $request->level_id, $request->org_id
								, $request->position_id, $request->appraisal_year));

			foreach ($sql_one as $one) {
				$detail = DB::select($sql_detail,array( $request->emp_id, $request->level_id, $request->org_id
									, $request->position_id, $request->appraisal_year, $one->item_id));
				$one->detail = $detail;
			}

		}//end Individual

		if($request->appraisal_type_id == 1){
			//Organization

			$sql_from1 = "
				select aps.structure_name
				, aps.structure_id
				, per.perspective_id
				, per.perspective_name
				, ai.item_id
				, ai.item_name
				, ROUND(count(air.period_id),2) as count_period
				, ROUND(avg(air.target_value),2) as target_value
				, uom.uom_name
				, ROUND(avg(air.actual_value),2) as actual_value
				, (case when 1={$system_config[0]->threshold} then 'Score' else '% Achievement' end) as name_score_achievement  -- threshold
				, (case when 1={$system_config[0]->threshold} then ROUND(avg(air.score),2)
					else ROUND(avg(air.percent_achievement),2) end) as score_achievement   -- threshold
				, ROUND(avg(air.weight_percent),2) as weight_percent -- /avg
				, (case when 1={$system_config[0]->threshold} then ROUND((avg(air.score)*avg(air.weight_percent)),2)  -- threshold
					else ROUND((avg(air.percent_achievement)*avg(air.weight_percent)),2)
					end) as weight_score
				, (case when 1={$system_config[0]->threshold} then aps.nof_target_score  -- threshold
					else (select avg(count_of_item) from structure_result where emp_result_id = air.emp_result_id
					and structure_id = aps.structure_id ) end) as divider  -- period_id
				, aps.form_id
				-- from appraisal_item_result air
				from emp_result e
				inner join appraisal_item_result air on air.emp_result_id = e.emp_result_id
				inner join appraisal_item ai on air.item_id = ai.item_id
				inner join perspective per on ai.perspective_id = per.perspective_id
				inner join appraisal_structure aps on ai.structure_id = aps.structure_id
				inner join appraisal_period pe on air.period_id = pe.period_id
				left join uom on ai.uom_id = uom.uom_id
				where air.level_id = ?  -- level_id
				and air.org_id = ?  -- org_id
				and pe.appraisal_year = ? -- appraisal_year
				and aps.form_id = 1
				and e.appraisal_type_id = 1
				group by aps.structure_name
				, aps.structure_id
				, per.perspective_id
				, per.perspective_name
				, ai.item_id
				, ai.item_name
				order by ai.structure_id asc, per.perspective_id asc, ai.item_name asc";

				$sql_detail = "
					select aps.structure_name
					, aps.structure_id
					, ai.item_id
					, ai.item_name
					, pe.period_id
					, pe.appraisal_period_desc
					, air.target_value
					, uom.uom_name
					, air.actual_value
					, (case when 1={$system_config[0]->threshold} then 'Score' else '% Achievement' end) as name_score_achievement  -- threshold
					, (case when 1={$system_config[0]->threshold} then air.score
						else air.percent_achievement end) as score_achievement   -- threshold
					, air.weight_percent
					, (case when 1={$system_config[0]->threshold} then (air.score)*(air.weight_percent)  -- threshold
						else (air.percent_achievement)*(air.weight_percent)
						end) as weight_score
					, aps.form_id
					-- from appraisal_item_result air
					from emp_result e
					inner join appraisal_item_result air on air.emp_result_id = e.emp_result_id
					inner join appraisal_item ai on air.item_id = ai.item_id
					inner join appraisal_structure aps on ai.structure_id = aps.structure_id
					inner join appraisal_period pe on air.period_id = pe.period_id
					left join uom on ai.uom_id = uom.uom_id
					where air.level_id = ?  -- level_id
					and air.org_id = ?  -- org_id
					and pe.appraisal_year = ? -- appraisal_year
					and aps.form_id = 1
					and ai.item_id = ?
					and e.appraisal_type_id = 1
					order by pe.period_id asc";

				$sql_one = DB::select($sql_from1,array( $request->level_id, $request->org_id, $request->appraisal_year));

				foreach ($sql_one as $one) {
					$detail = DB::select($sql_detail,array( $request->level_id, $request->org_id, $request->appraisal_year, $one->item_id));
					$one->detail = $detail;
				}

		}//end Organization

		$groups = array();
		foreach ($sql_one as $s) {  								//flow data each record
				$key = $s->structure_name;
				if (!isset($groups[$key])) {   			//check group structure_name have?
					$groups[$key] = array(   					//create group for structure_name when don't have (all fields in record)
						'items' => array($s),
						'structure_name' => $s->structure_name,
						'structure_id' => $s->structure_id,
						'count' => 1,
						'name_score_achievement' => $s->name_score_achievement,
						'sum' => $s->weight_score,			//sum every record
						'divider' => $s->divider,
						'form_id' => $s->form_id
					);
				} else {
					$groups[$key]['items'][] = $s; 		//put data (all fields in record) when structure_name have
					$groups[$key]['structure_name'] = $s->structure_name;
					$groups[$key]['structure_id'] = $s->structure_id;
					$groups[$key]['count'] += 1;
					$groups[$key]['name_score_achievement'] = $s->name_score_achievement;
					$groups[$key]['sum'] += $s->weight_score;		//sum weight_score for total
					$groups[$key]['divider'] += $s->divider;			//divider for tatol
					$groups[$key]['form_id'] = $s->form_id;
				}
		}

		return response()->json($groups);
	}

	//$sql_item = select item for current period
	// $sql_item = "select i.item_id, i.item_name, s.structure_id, s.structure_name, s.form_id, i.uom_id, s.nof_target_score
	// 	from appraisal_item_result a
	// 	inner join appraisal_item i on a.item_id = i.item_id
	// 	inner join appraisal_structure s on i.structure_id = s.structure_id
	// 	where a.period_id = ? -- period_id
	// 	and a.emp_id = ?  -- emp_id
	// 	and a.level_id = ?  -- level_id
	// 	and a.org_id = ?  -- org_id
	// 	and a.position_id = ?  -- position_id";

	// $sql_from2 = "
	// 	select ai.structure_name
	// 	, ai.structure_id
	// 	, ai.item_id
	// 	, ai.item_name
	// 	, count(pe.period_id) as num_period
	// 	, ROUND(avg(air.target_value),2) as target_value
	// 	, '' as uom_name
	// 	, 0 as actual_value
	// 	, '' as name_score_achievement
	// 	, 0 as score_achievement
	// 	, ROUND(avg(air.first_score),2) as first_score
	// 	, ROUND(avg(air.second_score),2) as second_score
	// 	, ROUND(avg(air.score),2) as score
	// 	, ROUND(avg(air.weight_percent),2) as weight_percent
	// 	, ROUND((avg(air.score)*avg(air.weight_percent)),2) as weight_score
	// 	, ai.nof_target_score as divider
	// 	, ai.form_id
	// 	from appraisal_item_result air
	// 	inner join (".$sql_item."
	// 		) ai on air.item_id = ai.item_id
	// 	inner join employee em on air.emp_id = em.emp_id
	// 	inner join appraisal_period pe on air.period_id = pe.period_id
	// 	inner join (SELECT appraisal_frequency_id, period_no, appraisal_year
	// 		FROM appraisal_period
	// 		WHERE period_id = ?) PERIOD on PERIOD.appraisal_year = pe.appraisal_year  -- period_id
	// 		AND PERIOD.appraisal_frequency_id = pe.appraisal_frequency_id
	// 		AND pe.period_no <= PERIOD.period_no
	// 	where ai.form_id = 2
	// 	and em.emp_id = ?  -- emp_id
	// 	and em.level_id = ?  -- level_id
	// 	and em.org_id = ?  -- org_id
	// 	and em.position_id = ?  -- position_id
	// 	group by ai.structure_name
	// 	, ai.structure_id
	// 	, ai.item_id
	// 	, ai.item_name";
		// order by ai.structure_id, ai.item_id
	// ,array($request->period_id, $request->emp_id, $request->level_id, $request->org_id, $request->position_id
	// ,$request->period_id, $request->emp_id, $request->level_id, $request->org_id, $request->position_id));

}
