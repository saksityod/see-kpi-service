<?php

namespace App\Http\Controllers;

use App\KPIType;
use App\EmpResult;
use App\AppraisalItemResult;
use App\EmpResultStage;
use App\Employee;

use Illuminate\Http\Request;
use DB;
use File;
use Auth;
use Excel;
use Response;
use Validator;
use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class ImportAssignmentController extends Controller
{

  public function __construct(){
	   $this->middleware('jwt.auth');
	}


  /**
   * Get Level list filter by Entity Type.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( appraisal_type_id )
   * @return \Illuminate\Http\Response
   */
 //  public function level_list(Request $request){
 //    if ($request->appraisal_type_id == "1") {
 //      $levels = DB::select("
 //        SELECT level_id, appraisal_level_name
 //        FROM appraisal_level
 //        WHERE is_active = 1
 //        AND is_org = 1
 //        ORDER BY level_id desc
 //      ");
 //    } else if($request->appraisal_type_id == "2"){
 //      $levels = DB::select("
 //        SELECT level_id, appraisal_level_name
 //        FROM appraisal_level
 //        WHERE is_active = 1
 //        AND is_individual = 1
 //        ORDER BY level_id desc
 //      ");
 //    } else {
 //      $levels = [];
 //    }

	// 	return response()->json($levels);
	// }

  //add by toto 2018-04-06 17:36
  public function level_list(Request $request){
      $all_emp = DB::select("
        SELECT sum(b.is_all_employee) count_no
        from employee a
        left outer join appraisal_level b
        on a.level_id = b.level_id
        where emp_code = '".Auth::id()."'
      ");

      if ($request->appraisal_type_id == "1") {
        if ($all_emp[0]->count_no > 0) {
          $items = DB::select("
            Select level_id, appraisal_level_name
            From appraisal_level
            Where is_active = 1
            and is_org = 1
            Order by level_id desc
          ");
        } else {
          $items = DB::select("
          select l.level_id, l.appraisal_level_name
          from appraisal_level l
          inner join org e
          on e.level_id = l.level_id
          inner join employee ee
          on ee.org_id = e.org_id
          where (ee.chief_emp_code = ? or ee.emp_code = ?)
          and l.is_org = 1
          group by l.level_id desc
          ", array(Auth::id(), Auth::id()));
        }
      } else if($request->appraisal_type_id == "2") {
        if ($all_emp[0]->count_no > 0) {
          $items = DB::select("
            Select level_id, appraisal_level_name
            From appraisal_level
            Where is_active = 1
            and is_individual = 1
            Order by level_id desc
          ");
        } else {
          $items = DB::select("
          select l.level_id, l.appraisal_level_name
          from appraisal_level l
          inner join employee ee
          on l.level_id = ee.level_id
          where (ee.chief_emp_code = ? or ee.emp_code = ?)
          and l.is_individual = 1
          group by l.level_id desc
          ", array(Auth::id(), Auth::id()));
        }
      } else {
        $items = [];
      }

    return response()->json($items);
  }



  /**
   * Get Org list filter by Entity Type and Level.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( appraisal_type_id, level_id[] )
   * @return \Illuminate\Http\Response
   */
 //  public function org_list(Request $request){
 //    $levelStr = (empty($request->level_id)) ? "''" : "'".implode("','", $request->level_id)."'" ;
 //    if ($request->appraisal_type_id == "1") {
 //      $orgs = DB::select("
 //  			SELECT org_id, org_name
 //  			FROM org
 //  			WHERE is_active = 1
 //  			AND level_id IN({$levelStr})
 //  			ORDER BY org_id
 //  		");
 //    } else if($request->appraisal_type_id == "2") {
 //      $orgs = DB::select("
 //        SELECT emp.org_id, org.org_name
 //        FROM employee emp, org
 //        WHERE emp.org_id = org.org_id
 //        GROUP BY emp.org_id
 //        ORDER BY emp.org_id
 //      ");
 //    } else {
 //      $orgs = [];
 //    }

	// 	return response()->json($orgs);
	// }

  //add by toto 2018-04-06 17:36
  public function org_list(Request $request){
    $levelStr = (empty($request->level_id)) ? "''" : "'".implode("','", $request->level_id)."'" ;

    $all_emp = DB::select("
        SELECT sum(b.is_all_employee) count_no
        from employee a
        left outer join appraisal_level b
        on a.level_id = b.level_id
        where emp_code = '".Auth::id()."'
      ");

    if ($request->appraisal_type_id == "1") {
      if ($all_emp[0]->count_no > 0) {
          $orgs = DB::select("
            SELECT org_id, org_name
            FROM org
            WHERE is_active = 1
            AND level_id IN({$levelStr})
            ORDER BY org_id
          ");
        } else {
          $orgs = DB::select("
          SELECT org.org_id, org.org_name
          FROM org
          left join employee ee
          on ee.org_id = org.org_id
          where org.is_active = 1
          and org.level_id IN({$levelStr})
          and (ee.chief_emp_code = ? or ee.emp_code = ?)
          and org.is_active = 1
          GROUP BY ee.org_id
          ORDER BY ee.org_id
          ", array(Auth::id(), Auth::id()));
        }
    } else if($request->appraisal_type_id == "2") {

      if ($all_emp[0]->count_no > 0) {
          $orgs = DB::select("
            SELECT emp.org_id, org.org_name
            FROM employee emp, org
            WHERE emp.org_id = org.org_id
            GROUP BY emp.org_id
            ORDER BY emp.org_id
          ");
        } else {
          $orgs = DB::select("
          SELECT org.org_id, org.org_name
          FROM org
          left join employee ee
          on ee.org_id = org.org_id
          WHERE (ee.chief_emp_code = ? or ee.emp_code = ?)
          and org.is_active = 1
          GROUP BY ee.org_id
          ORDER BY ee.org_id
          ", array(Auth::id(), Auth::id()));
        }
    } else {
      $orgs = [];
    }

    return response()->json($orgs);
  }



  /**
   * Get Employee list filter by org_id, emp_code, emp_name
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( org_id[], emp_code, emp_name)
   * @return \Illuminate\Http\Response
   */
  public function auto_employee_name(Request $request)
	{
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

    $orgIdStr = (empty($request->org_id)) ? "' '" : "'".implode("','", $request->org_id)."'" ;
		empty($request->org_id) ? $org = "" : $org = " and org_id in({$orgIdStr}) ";

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select emp_code, emp_name
				From employee
				Where emp_name like ?
				and is_active = 1
			" . $org . "
				Order by emp_name
			", array('%'.$request->emp_name.'%'));
		} else {
			$items = DB::select("
				Select emp_code, emp_name
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



  /**
   * Display item list.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( level_id[], org_id[], position_id )
   * @return \Illuminate\Http\Response
   */
   public function item_list(Request $request){
     $levelStr = (empty($request->level_id)) ? "' '" : "'".implode("','", $request->level_id)."'";
     $orgIdStr = (empty($request->org_id)) ? "' '" : "'".implode("','", $request->org_id)."'" ;

     // In case, do not specify a position for retrieval by ignoring position.
     if(empty($request->position_id)){
       $positionJoinStr = " ";
       $positionStr = " ";
     } else {
       $positionJoinStr = "INNER JOIN appraisal_item_position post ON post.item_id = ai.item_id";
       $positionStr = "AND post.position_id = '{$request->position_id}'";
     }

     $items = DB::select("
      SELECT distinct ai.structure_id, strc.structure_name, ai.item_id, ai.item_name, strc.form_id
      FROM appraisal_item ai
      INNER JOIN appraisal_structure strc ON strc.structure_id = ai.structure_id
      INNER JOIN appraisal_item_level vel ON vel.item_id = ai.item_id
      INNER JOIN appraisal_item_org iorg ON iorg.item_id = ai.item_id
      INNER JOIN appraisal_criteria ac ON ac.appraisal_level_id = vel.level_id AND ac.structure_id = strc.structure_id
      ".$positionJoinStr."
      WHERE strc.is_active = 1
      AND vel.level_id IN({$levelStr})
      AND iorg.org_id IN({$orgIdStr})
      ".$positionStr."
      ORDER BY ai.structure_id, ai.item_id");

    $groupData = [];
    foreach ($items as $str) {
      if (!in_array($str->structure_id, array_column($groupData, "structure_id"))) {

        // Append data into sub group
        $dataArr = [];
        foreach ($items as $data) {
          if ($str->structure_id == $data->structure_id) {
            array_push($dataArr, [
              "item_id" => $data->item_id,
              "item_name" => $data->item_name
            ]);
          }
        }

        // Append to group
        array_push($groupData, [
          "structure_id" => $str->structure_id,
          "structure_name" => $str->structure_name,
          "data" => $dataArr
        ]);
      }
    }
    return response()->json(['status' => 200, 'data' => $groupData]);
  }



   /**
    * Assignment export to Excel (Individual).
    *
    * @author P.Wirun (GJ)
    * @param  \Illuminate\Http\Request
    *         $request (appraisal_type_id, position_id, emp_id, period_id, appraisal_year,
    *                   frequency_id, org_id[], appraisal_item_id[], appraisal_level_id[])
    * @return \Illuminate\Http\Response
    */
    public function export_template_individual(Request $request){

      // Set file name and directory.
      set_time_limit(1000); //
      $extension = "xlsx";
      $fileName = "import_assignment_".date('Ymd His');;  //yyyymmdd hhmmss

      try {
        // Set Input parameter
        $appraisal_type_id = $request->appraisal_type_id;
        $appraisal_level_id = (empty($request->appraisal_level_id)) ? "''" : $request->appraisal_level_id;
        $org_id = (empty($request->org_id)) ? "''" : $request->org_id;
        $appraisal_item_id = (empty($request->appraisal_item_id)) ? "''" : $request->appraisal_item_id;
        // $appraisal_level_id = (empty($request->appraisal_level_id)) ? "''" : "'".implode("','", $request->appraisal_level_id)."'" ;
        // $org_id = (empty($request->org_id)) ? "''" : "'".implode("','", $request->org_id)."'" ;
        // $appraisal_item_id = (empty($request->appraisal_item_id)) ? "''" : "'".implode("','", $request->appraisal_item_id)."'" ;
        $position_id = $request->position_id;
        $emp_code = $request->emp_id;
        $period_id = $request->period_id;
        $appraisal_year = $request->appraisal_year;
        $frequency_id = $request->frequency_id;

        // Set parameter string in sql where clause
        $positionStr = (empty($position_id)) ? "" : " emp.position_id = '{$position_id}'";
        $empStr = (empty($emp_code)) ? "" : " emp.emp_code = '{$emp_code}'";
        if (!empty($positionStr) && !empty($empStr)) {
          $empContStr = " and ";
        } else if(empty($positionStr) && empty($empStr)) {
          $empContStr = " 1=1 ";
        } else {
          $empContStr = "";
        }
        $periodStr = (empty($period_id))
          ? "
            AND prd.appraisal_year = '{$appraisal_year}'
            AND prd.appraisal_frequency_id = '{$frequency_id}'"
          : "AND prd.period_id = '{$period_id}'" ;

        $items = DB::select("
          SELECT
          	prd.period_id, prd.year, prd.start_date, prd.end_date,
          	typ.appraisal_type_id, typ.appraisal_type_name, emp.default_stage_id as stage_id, emp.status,
          	emp.level_id, emp.appraisal_level_name level_name, emp.org_id,
          	emp.org_name, emp.position_id, emp.position_name, emp.chief_emp_id,
          	emp.chief_emp_code, emp.chief_emp_name, emp.emp_id, emp.emp_code,
          	emp.emp_name, item.item_id appraisal_item_id,
          	item.item_name appraisal_item_name, item.uom_name,
            item.max_value, item.unit_deduct_score, item.value_get_zero,
          	item.structure_name, item.form_id, item.nof_target_score, item.is_value_get_zero
          FROM(
          	SELECT
          		emp.level_id, vel.appraisal_level_name, vel.default_stage_id,
          		emp.org_id, org.org_name,
          		emp.position_id, pos.position_name,
          		emp.emp_id, emp.emp_code, emp.emp_name,
          		chf.emp_id chief_emp_id, chf.emp_code chief_emp_code, chf.emp_name chief_emp_name,
              stg.status
          	FROM employee emp
          	LEFT OUTER JOIN employee chf ON chf.emp_code = emp.chief_emp_code
          	INNER JOIN appraisal_level vel ON vel.level_id = emp.level_id
          	INNER JOIN org ON org.org_id = emp.org_id
          	INNER JOIN position pos ON pos.position_id = emp.position_id
            LEFT OUTER JOIN appraisal_stage stg ON stg.stage_id = vel.default_stage_id
          	WHERE vel.is_active = 1
          	AND org.is_active = 1
          	AND pos.is_active = 1
            AND emp.is_active = 1
          	AND emp.level_id IN({$appraisal_level_id})
          	AND emp.org_id IN({$org_id})
          	AND (".$positionStr.$empContStr.$empStr.")
          )emp
          INNER JOIN (
          	SELECT ail.level_id, aio.org_id,
          		itm.item_id, itm.item_name, uom.uom_name,
              itm.max_value, itm.unit_deduct_score, itm.value_get_zero,
          		strc.structure_name, strc.form_id, strc.nof_target_score, strc.is_value_get_zero
          	FROM appraisal_item itm
          	LEFT JOIN appraisal_item_level ail ON ail.item_id = itm.item_id
          	LEFT JOIN appraisal_item_org aio ON aio.item_id = itm.item_id
          	LEFT JOIN appraisal_structure strc ON strc.structure_id = itm.structure_id
          	LEFT JOIN uom ON uom.uom_id = itm.uom_id
          	WHERE itm.item_id IN({$appraisal_item_id})
          	AND ail.level_id IN({$appraisal_level_id})
          	AND aio.org_id IN({$org_id})
          )item ON item.level_id = emp.level_id AND item.org_id = emp.org_id
          CROSS JOIN(
          	SELECT apt.appraisal_type_id, apt.appraisal_type_name,
          		aps.stage_id, aps.status
          	FROM appraisal_type apt
          	LEFT JOIN appraisal_stage aps
          		ON aps.appraisal_type_id = apt.appraisal_type_id
          		AND aps.stage_id = (
          			SELECT MIN(stage_id) FROM appraisal_stage
          			WHERE appraisal_type_id = apt.appraisal_type_id
          		)
          	WHERE apt.appraisal_type_id = '{$appraisal_type_id}'
          )typ
          CROSS JOIN(
          	SELECT prd.period_id, prd.appraisal_year year, prd.start_date, prd.end_date
          	FROM appraisal_period prd
          	WHERE 1 = 1
          	".$periodStr."
          )prd
        ");

        // Generate Excel from query result. Return 404, If not found data.
        if(!empty($items)){
          // Set grouped to create sheets.
          $itemList = [];
          $form1Key = 0; $form2Key = 0; $form3Key = 0;
          foreach($items as $value) {
            // Get assigned value //
            $assignedInfo = [];
            $assignedQry = DB::select("
              SELECT target_value, weight_percent,
              	score0, score1, score2, score3, score4, score5
              FROM appraisal_item_result
              WHERE period_id = {$value->period_id}
              AND emp_id = {$value->emp_id}
              AND org_id = {$value->org_id}
              AND position_id = {$value->position_id}
              AND item_id = {$value->appraisal_item_id}
              AND level_id = {$value->level_id}
              LIMIT 1
            ");
            if (empty($assignedQry)) {
              $assignedInfo["target_value"] = "";
              $assignedInfo["weight_percent"] = "";
              $assignedInfo["score0"] = "";
              $assignedInfo["score1"] = "";
              $assignedInfo["score2"] = "";
              $assignedInfo["score3"] = "";
              $assignedInfo["score4"] = "";
              $assignedInfo["score5"] = "";
            } else {
              foreach ($assignedQry as $asVal) {
                $assignedInfo["target_value"] = $asVal->target_value;
                $assignedInfo["weight_percent"] = $asVal->weight_percent;
                $assignedInfo["score0"] = $asVal->score0;
                $assignedInfo["score1"] = $asVal->score1;
                $assignedInfo["score2"] = $asVal->score2;
                $assignedInfo["score3"] = $asVal->score3;
                $assignedInfo["score4"] = $asVal->score4;
                $assignedInfo["score5"] = $asVal->score5;
              }
            }

            if ($value->form_id == "1") {
              $itemList[$value->structure_name][$form1Key] = [
                "period_id" => $value->period_id,
                "year" => $value->year,
                "start_date" => $value->start_date,
                "end_date" => $value->end_date,
                "appraisal_type_id" => $value->appraisal_type_id,
                "appraisal_type_name" => $value->appraisal_type_name,
                "stage_id" => $value->stage_id,
                "status" => $value->status,
                "level_id" => $value->level_id,
                "level_name" => $value->level_name,
                "org_id" => $value->org_id,
                "org_name" => $value->org_name,
                "position_id" => $value->position_id,
                "position_name" => $value->position_name,
                "chief_emp_id" => $value->chief_emp_id,
                "chief_emp_code" => $value->chief_emp_code,
                "chief_emp_name" => $value->chief_emp_name,
                "emp_id" => $value->emp_id,
                "emp_code" => $value->emp_code,
                "emp_name" => $value->emp_name,
                "appraisal_item_id" => $value->appraisal_item_id,
                "appraisal_item_name" => $value->appraisal_item_name,
                "uom_name" => $value->uom_name,
                "target" => $assignedInfo["target_value"],
                "weight" => $assignedInfo["weight_percent"]
                // Range by appraisal_structure.nof_target_score
              ];

              // Generate range by appraisal_structure.nof_target_score
              $rangekey = 0;
              while($rangekey <= $value->nof_target_score) {
                $itemList[$value->structure_name][$form1Key]["range".$rangekey] = $assignedInfo["score".$rangekey];
                $rangekey = $rangekey+1;
              }
              $form1Key = $form1Key+1;

            } else if($value->form_id == "2") {
              $itemList[$value->structure_name][$form2Key] = [
                "period_id" => $value->period_id,
                "year" => $value->year,
                "start_date" => $value->start_date,
                "end_date" => $value->end_date,
                "appraisal_type_id" => $value->appraisal_type_id,
                "appraisal_type_name" => $value->appraisal_type_name,
                "stage_id" => $value->stage_id,
                "status" => $value->status,
                "level_id" => $value->level_id,
                "level_name" => $value->level_name,
                "org_id" => $value->org_id,
                "org_name" => $value->org_name,
                "position_id" => $value->position_id,
                "position_name" => $value->position_name,
                "chief_emp_id" => $value->chief_emp_id,
                "chief_emp_code" => $value->chief_emp_code,
                "chief_emp_name" => $value->chief_emp_name,
                "emp_id" => $value->emp_id,
                "emp_code" => $value->emp_code,
                "emp_name" => $value->emp_name,
                "appraisal_item_id" => $value->appraisal_item_id,
                "appraisal_item_name" => $value->appraisal_item_name,
                "target" => $assignedInfo["target_value"],
                "weight" => $assignedInfo["weight_percent"]
              ];
              $form2Key = $form2Key + 1;

            }else if($value->form_id == "3"){

              if($value->is_value_get_zero==1) {
                $column_value_get_zero = "value_get_zero";
                $value_get_zero = $value->value_get_zero;
              } else {
                $column_value_get_zero = "";
                $value_get_zero = "";
              }

              $itemList[$value->structure_name][$form3Key] = [
                "period_id" => $value->period_id,
                "year" => $value->year,
                "start_date" => $value->start_date,
                "end_date" => $value->end_date,
                "appraisal_type_id" => $value->appraisal_type_id,
                "appraisal_type_name" => $value->appraisal_type_name,
                "stage_id" => $value->stage_id,
                "status" => $value->status,
                "level_id" => $value->level_id,
                "level_name" => $value->level_name,
                "org_id" => $value->org_id,
                "org_name" => $value->org_name,
                "position_id" => $value->position_id,
                "position_name" => $value->position_name,
                "chief_emp_id" => $value->chief_emp_id,
                "chief_emp_code" => $value->chief_emp_code,
                "chief_emp_name" => $value->chief_emp_name,
                "emp_id" => $value->emp_id,
                "emp_code" => $value->emp_code,
                "emp_name" => $value->emp_name,
                "appraisal_item_id" => $value->appraisal_item_id,
                "appraisal_item_name" => $value->appraisal_item_name,
                "max_value" => $value->max_value,
                "score_per_unit" => $value->unit_deduct_score,
                "".$column_value_get_zero."" => $value_get_zero
              ];
              $form3Key = $form3Key + 1;
            }
          }

          Excel::create($fileName, function($excel) use ($itemList) {

            foreach ($itemList as $key => $group) {

              $excel->sheet($key, function($sheet) use ($key, $itemList){
                // Inside the sheet closure --> fromArray($source, $nullValue, $startCell, $strictNullComparison, $headingGeneration)
                $sheet->fromArray($itemList[$key], null, 'A1', true);
              });

            }

          })->download($extension); //->store($extension, $outpath);
          //return response()->download($outpath."/".$fileName.".".$extension, $fileName.".".$extension);
        }else{
          return response()->json(['status' => 404, 'data' => 'Assignment Item Result not found.']);
        }

      } catch(QueryException $e) {
        return response()->json(['status' => 404, 'data' => 'Assignment Item Result is set time limit 1000 sec.']);
      }
    }



    /**
     * Assignment export to Excel (Organization).
     *
     * @author P.Wirun (GJ)
     * @param  \Illuminate\Http\Request
     *         $request (appraisal_type_id, position_id, emp_id, period_id, appraisal_year,
     *                   frequency_id, org_id[], appraisal_item_id[], appraisal_level_id[])
     * @return \Illuminate\Http\Response
     */
     public function export_template_organization(Request $request){

       // Set file name and directory.
       set_time_limit(1000); //
       $extension = "xlsx";
       $fileName = "import_assignment_".date('Ymd His');;  //yyyymmdd hhmmss

       try {
         // Set Input parameter
         $appraisal_type_id = $request->appraisal_type_id;
         $appraisal_level_id = (empty($request->appraisal_level_id)) ? "''" : $request->appraisal_level_id;
         $org_id = (empty($request->org_id)) ? "''" : $request->org_id;
         $appraisal_item_id = (empty($request->appraisal_item_id)) ? "''" : $request->appraisal_item_id;
         // $appraisal_level_id = (empty($request->appraisal_level_id)) ? "''" : "'".implode("','", $request->appraisal_level_id)."'" ;
         // $org_id = (empty($request->org_id)) ? "''" : "'".implode("','", $request->org_id)."'" ;
         // $appraisal_item_id = (empty($request->appraisal_item_id)) ? "''" : "'".implode("','", $request->appraisal_item_id)."'" ;
         $period_id = $request->period_id;
         $appraisal_year = $request->appraisal_year;
         $frequency_id = $request->frequency_id;

         // Set parameter string in sql where clause
         $periodStr = (empty($period_id))
           ? "
             AND prd.appraisal_year = '{$appraisal_year}'
             AND prd.appraisal_frequency_id = '{$frequency_id}'"
           : "AND prd.period_id = '{$period_id}'" ;

         $items = DB::select("
           SELECT
           	prd.period_id, prd.year, prd.start_date, prd.end_date,
            typ.appraisal_type_id, typ.appraisal_type_name, org.default_stage_id as stage_id, org.status,
            org.level_id, org.appraisal_level_name level_name, org.org_id,
            org.org_name, item.item_id appraisal_item_id,
            item.item_name appraisal_item_name, item.uom_name,
            item.max_value, item.unit_deduct_score, item.value_get_zero,
            item.structure_name, item.form_id, item.nof_target_score, item.is_value_get_zero
           FROM(
           	SELECT
           		org.level_id, vel.appraisal_level_name, vel.default_stage_id,
              org.org_id, org.org_name, stg.status
           	FROM org
           	INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
            LEFT OUTER JOIN appraisal_stage stg ON stg.stage_id = vel.default_stage_id
           	WHERE vel.is_active = 1
           	AND org.is_active = 1
           	AND org.level_id IN({$appraisal_level_id})
           	AND org.org_id IN({$org_id})
           )org
           INNER JOIN (
           	SELECT ail.level_id, aio.org_id,
           		itm.item_id, itm.item_name, uom.uom_name,
               itm.max_value, itm.unit_deduct_score, itm.value_get_zero,
           		strc.structure_name, strc.form_id, strc.nof_target_score, strc.is_value_get_zero
           	FROM appraisal_item itm
           	LEFT JOIN appraisal_item_level ail ON ail.item_id = itm.item_id
           	LEFT JOIN appraisal_item_org aio ON aio.item_id = itm.item_id
           	LEFT JOIN appraisal_structure strc ON strc.structure_id = itm.structure_id
           	LEFT JOIN uom ON uom.uom_id = itm.uom_id
           	WHERE itm.item_id IN({$appraisal_item_id})
           	AND ail.level_id IN({$appraisal_level_id})
           	AND aio.org_id IN({$org_id})
           )item ON item.level_id = org.level_id AND item.org_id = org.org_id
           CROSS JOIN(
           	SELECT apt.appraisal_type_id, apt.appraisal_type_name,
           		aps.stage_id, aps.status
           	FROM appraisal_type apt
           	LEFT JOIN appraisal_stage aps
           		ON aps.appraisal_type_id = apt.appraisal_type_id
           		AND aps.stage_id = (
           			SELECT MIN(stage_id) FROM appraisal_stage
           			WHERE appraisal_type_id = apt.appraisal_type_id
           		)
           	WHERE apt.appraisal_type_id = '{$appraisal_type_id}'
           )typ
           CROSS JOIN(
           	SELECT prd.period_id, prd.appraisal_year year, prd.start_date, prd.end_date
           	FROM appraisal_period prd
           	WHERE 1 = 1
           	".$periodStr."
           )prd
         ");

         // Generate Excel from query result. Return 404, If not found data.
         if(!empty($items)){
           // Set grouped to create sheets.
           $itemList = [];
           $form1Key = 0; $form2Key = 0; $form3Key = 0;
           foreach($items as $value) {
             // Get assigned value //
             $assignedInfo = [];
             $assignedQry = DB::select("
               SELECT target_value, weight_percent,
               	score0, score1, score2, score3, score4, score5
               FROM appraisal_item_result
               WHERE period_id = {$value->period_id}
               AND emp_id is null
               AND org_id = {$value->org_id}
               AND position_id is null
               AND item_id = {$value->appraisal_item_id}
               AND level_id = {$value->level_id}
               LIMIT 1
             ");
             if (empty($assignedQry)) {
               $assignedInfo["target_value"] = "";
               $assignedInfo["weight_percent"] = "";
               $assignedInfo["score0"] = "";
               $assignedInfo["score1"] = "";
               $assignedInfo["score2"] = "";
               $assignedInfo["score3"] = "";
               $assignedInfo["score4"] = "";
               $assignedInfo["score5"] = "";
             } else {
               foreach ($assignedQry as $asVal) {
                 $assignedInfo["target_value"] = $asVal->target_value;
                 $assignedInfo["weight_percent"] = $asVal->weight_percent;
                 $assignedInfo["score0"] = $asVal->score0;
                 $assignedInfo["score1"] = $asVal->score1;
                 $assignedInfo["score2"] = $asVal->score2;
                 $assignedInfo["score3"] = $asVal->score3;
                 $assignedInfo["score4"] = $asVal->score4;
                 $assignedInfo["score5"] = $asVal->score5;
               }
             }

             if ($value->form_id == "1") {
               $itemList[$value->structure_name][$form1Key] = [
                 "period_id" => $value->period_id,
                 "year" => $value->year,
                 "start_date" => $value->start_date,
                 "end_date" => $value->end_date,
                 "appraisal_type_id" => $value->appraisal_type_id,
                 "appraisal_type_name" => $value->appraisal_type_name,
                 "stage_id" => $value->stage_id,
                 "status" => $value->status,
                 "level_id" => $value->level_id,
                 "level_name" => $value->level_name,
                 "org_id" => $value->org_id,
                 "org_name" => $value->org_name,
                 "appraisal_item_id" => $value->appraisal_item_id,
                 "appraisal_item_name" => $value->appraisal_item_name,
                 "uom_name" => $value->uom_name,
                 "target" => $assignedInfo["target_value"],
                 "weight" => $assignedInfo["weight_percent"]
                 //-- Generate range by appraisal_structure.nof_target_score --//
               ];

               // Generate range by appraisal_structure.nof_target_score
               $rangekey = 0;
               while($rangekey <= $value->nof_target_score) {
                 $itemList[$value->structure_name][$form1Key]["range".$rangekey] = $assignedInfo["score".$rangekey];
                 $rangekey = $rangekey+1;
               }
               $form1Key = $form1Key+1;

             } else if($value->form_id == "2") {
                $itemList[$value->structure_name][$form2Key] = [
                  "period_id" => $value->period_id,
                  "year" => $value->year,
                  "start_date" => $value->start_date,
                  "end_date" => $value->end_date,
                  "appraisal_type_id" => $value->appraisal_type_id,
                  "appraisal_type_name" => $value->appraisal_type_name,
                  "stage_id" => $value->stage_id,
                  "status" => $value->status,
                  "level_id" => $value->level_id,
                  "level_name" => $value->level_name,
                  "org_id" => $value->org_id,
                  "org_name" => $value->org_name,
                  "appraisal_item_id" => $value->appraisal_item_id,
                  "appraisal_item_name" => $value->appraisal_item_name,
                  "target" => $assignedInfo["target_value"],
                  "weight" => $assignedInfo["weight_percent"]
                ];
                $form2Key = $form2Key + 1;

             }else if($value->form_id == "3"){

              if($value->is_value_get_zero==1) {
                $column_value_get_zero = "value_get_zero";
                $value_get_zero = $value->value_get_zero;
              } else {
                $column_value_get_zero = "";
                $value_get_zero = "";
              }

               $itemList[$value->structure_name][$form3Key] = [
                 "period_id" => $value->period_id,
                 "year" => $value->year,
                 "start_date" => $value->start_date,
                 "end_date" => $value->end_date,
                 "appraisal_type_id" => $value->appraisal_type_id,
                 "appraisal_type_name" => $value->appraisal_type_name,
                 "stage_id" => $value->stage_id,
                 "status" => $value->status,
                 "level_id" => $value->level_id,
                 "level_name" => $value->level_name,
                 "org_id" => $value->org_id,
                 "org_name" => $value->org_name,
                 "appraisal_item_id" => $value->appraisal_item_id,
                 "appraisal_item_name" => $value->appraisal_item_name,
                 "max_value" => $value->max_value,
                 "score_per_unit" => $value->unit_deduct_score,
                 "".$column_value_get_zero."" => $value_get_zero
               ];
               $form3Key = $form3Key + 1;
             }
           }

           Excel::create($fileName, function($excel) use ($itemList) {

             foreach ($itemList as $key => $group) {

               $excel->sheet($key, function($sheet) use ($key, $itemList){
                // Inside the sheet closure --> fromArray($source, $nullValue, $startCell, $strictNullComparison, $headingGeneration)
                $sheet->fromArray($itemList[$key], null, 'A1', true);
               });

             }

           })->download($extension); //->store($extension, $outpath);
           //return response()->download($outpath."/".$fileName.".".$extension, $fileName.".".$extension);
         }else{
           return response()->json(['status' => 404, 'data' => 'Assignment Item Result not found.']);
         }
       } catch(QueryException $e) {
         return response()->json(['status' => 404, 'data' => 'Assignment Item Result is set time limit 1000 sec.']);
       }
     }



    /**
     * Assignment import from Excel.
     *
     * @author P.Wirun (GJ)
     * @param  \Illuminate\Http\Request   $request (file())
     * @return \Illuminate\Http\Response
     */
    public function import_template(Request $request) {
      $errors = [];
      $startFnDttm = date("Y-m-d H:i:s");

      // Get active threshold group id
      $thresholdGroupId = 0;
      $thresholdGroup = DB::select("
        SELECT result_threshold_group_id
        FROM result_threshold_group
        WHERE is_active = 1
        LIMIT 1"
      );
      foreach ($thresholdGroup as $value) {
        $thresholdGroupId = $value->result_threshold_group_id;
      }

      // Get file from parameter
      foreach ($request->file() as $f) {

        // Sheet to array
        $sheetArr = Excel::load($f)->getSheetNames();

        // Loop through all sheets
        for ($i=0; $i < count($sheetArr); $i++) {
          $sheets = Excel::selectSheets($sheetArr[$i])->load($f, function($reader){})->get();
          $sheetError = false;
          DB::beginTransaction();

          // Loop through all rows
          foreach ($sheets as $key => $row) {
            if($row->appraisal_type_id == "1"){
              $validator = Validator::make($row->all(), [
                 "period_id" => "required|numeric",
                 "year" => "numeric",
                 "start_date" => "date",
                 "end_date" => "date",
                 "appraisal_type_id" => "required|numeric",
                 "stage_id" => "required|numeric",
                 "status" => "required",
                 "level_id" => "required|numeric",
                 "org_id" => "required|numeric",
                 "position_id" => "numeric",
                 "chief_emp_id" => "numeric",
                 "emp_id" => "numeric",
                 "appraisal_item_id" => "required|numeric",
                 "appraisal_item_name" => "required",
                 "target" => "sometimes|required|numeric",
                 "weight" => "sometimes|required|numeric",
                 "range0" => "sometimes|required|numeric",
                 "range1" => "sometimes|required|numeric",
                 "range2" => "sometimes|required|numeric",
                 "range3" => "sometimes|required|numeric",
                 "range4" => "sometimes|required|numeric",
                 "range5" => "sometimes|required|numeric",
              ]);
            } else {
              $validator = Validator::make($row->all(), [
                 "period_id" => "required|numeric",
                 "year" => "numeric",
                 "start_date" => "date",
                 "end_date" => "date",
                 "appraisal_type_id" => "required|numeric",
                 "stage_id" => "required|numeric",
                 "status" => "required",
                 "level_id" => "required|numeric",
                 "org_id" => "required|numeric",
                 "appraisal_item_id" => "required|numeric",
                 "appraisal_item_name" => "required",
                 "target" => "sometimes|required|numeric",
                 "weight" => "sometimes|required|numeric",
                 "range0" => "sometimes|required|numeric",
                 "range1" => "sometimes|required|numeric",
                 "range2" => "sometimes|required|numeric",
                 "range3" => "sometimes|required|numeric",
                 "range4" => "sometimes|required|numeric",
                 "range5" => "sometimes|required|numeric",
              ]);
            }

            if ($validator->fails()) {
              $errors[] = [
                "title"=>"Sheet:".$sheetArr[$i],
                "period_id"=>$row->period_id, "appraisal_type_id"=>$row->appraisal_type_id,
                "level_id"=>$row->level_id, "org_id"=>$row->org_id, "emp_id"=>$row->emp_id,
                "error_desc" => $validator->errors()
              ];
              return response()->json(['status' => 400, 'errors' => $errors]);
              //$sheetError = true;
            } else {

              // Get appraisal_item info.
              $itemInfo = [];
              $itemInfoQry = DB::select("
                SELECT item_id, unit_deduct_score, value_get_zero, structure_id
                FROM appraisal_item
                WHERE item_id = {$row->appraisal_item_id}
                LIMIT 1"
              );
              foreach ($itemInfoQry as $item) {
                $itemInfo["unit_deduct_score"] = $item->unit_deduct_score;
                $itemInfo["value_get_zero"] = $item->value_get_zero;
                $itemInfo["structure_id"] = $item->structure_id;
              }

              // Get appraisal_criteria info
              $criteriaInfoQry = DB::select("
                SELECT weight_percent
                FROM appraisal_criteria
                WHERE appraisal_level_id = {$row->level_id}
                AND structure_id = {$itemInfo["structure_id"]}
                LIMIT 1"
              );

              // -- Insert/Update @emp_result --------------------------------//
              $existEmpResultId = 0;
              $currentEmpResultId = 0;
              $existEmpStr = ($row->appraisal_type_id == "1") ? "AND emp_id is null" : "AND emp_id = {$row->emp_id}" ;
              $existPositionStr = ($row->appraisal_type_id == "1") ? "AND position_id is null" : "AND position_id = {$row->position_id}" ;
              $empResultExist = DB::select("
                SELECT emp_result_id
                FROM emp_result
                WHERE period_id = {$row->period_id}
                AND appraisal_type_id = {$row->appraisal_type_id}
                AND level_id = {$row->level_id}
                AND org_id = {$row->org_id}
                ".$existEmpStr."
                ".$existPositionStr."
                LIMIT 1"
              );
              foreach ($empResultExist as $value) {
                $existEmpResultId = $value->emp_result_id;
              }

              //-- Checking if record exists in emp_result.
              if (empty($empResultExist)) {
                //---- Insert @emp_result
    						$empResult = new EmpResult;
    						$empResult->period_id = $row->period_id;
                $empResult->appraisal_type_id = $row->appraisal_type_id;
                $empResult->level_id = $row->level_id;
                $empResult->org_id = $row->org_id;
                $empResult->emp_id = $row->emp_id;
    						$empResult->position_id = $row->position_id;
                $empResult->chief_emp_id = $row->chief_emp_id;
                $empResult->result_score = "0";
                $empResult->result_threshold_group_id = $thresholdGroupId;
                $empResult->raise_amount = "0";
                $empResult->new_s_amount = "0";
                $empResult->b_rate = "0";
                $empResult->b_amount = "0";
                $empResult->stage_id = $row->stage_id;
                $empResult->status = $row->status;
                $empResult->created_by = Auth::id();
                $empResult->updated_by = Auth::id();
                try {
                  // Insert @emp_result
    							$empResult->save();
                  $currentEmpResultId = $empResult->emp_result_id;
    						} catch (Exception $e) {
                  $errors[] = [
                    "title"=>"Sheet:".$sheetArr[$i],
                    "period_id"=>$row->period_id, "appraisal_type_id"=>$row->appraisal_type_id,
                    "level_id"=>$row->level_id, "org_id"=>$row->org_id, "emp_id"=>$row->emp_id,
                    "error_desc" => array(substr($e,0,254))
                  ];
                  $sheetError = true;
    						}
              } else {
                // Update @emp_result
                $updateStatus = EmpResult::where(
                  "emp_result_id", $existEmpResultId
                )->update([
                  "position_id" => $row->position_id,
                  "chief_emp_id" => $row->chief_emp_id,
                  "result_threshold_group_id" => $thresholdGroupId,
                 // "stage_id" => $row->stage_id,
                 // "status" => $row->status,
                  "updated_by" => Auth::id(),
                  "updated_dttm" => date("Y-m-d H:i:s")
                ]);
                $currentEmpResultId = $existEmpResultId;
              }
              // -- End -- Insert/Update @emp_result -------------------------//


              // -- Start -- Insert/Update @appraisal_item_result ------------//
              $existItemResultId = 0;
              $itemResultExist = DB::select("
                SELECT item_result_id
                FROM appraisal_item_result
                WHERE emp_result_id = {$currentEmpResultId}
                AND item_id = {$row->appraisal_item_id}
                AND period_id = {$row->period_id}
                AND level_id = {$row->level_id}
                AND org_id = {$row->org_id}
                LIMIT 1"
              );
              foreach ($itemResultExist as $value) {
                $existItemResultId = $value->item_result_id;
              }

              //-- Checking if record exists in appraisal_item_result.
              if (empty($itemResultExist)) {
                //---- Insert @appraisal_item_result
                $appraisalItemResult = new AppraisalItemResult;
                $appraisalItemResult->emp_result_id = $currentEmpResultId;
                $appraisalItemResult->item_id = $row->appraisal_item_id;
                $appraisalItemResult->period_id = $row->period_id;
                $appraisalItemResult->level_id = $row->level_id;
                $appraisalItemResult->org_id = $row->org_id;
                // ---------------------------------------------------- //
                $appraisalItemResult->emp_id = $row->emp_id;
                $appraisalItemResult->position_id = $row->position_id;
                $appraisalItemResult->item_name = $row->appraisal_item_name;
                $appraisalItemResult->chief_emp_id = $row->chief_emp_id;
                $appraisalItemResult->score0 = $row->range0;
                $appraisalItemResult->score1 = $row->range1;
                $appraisalItemResult->score2 = $row->range2;
                $appraisalItemResult->score3 = $row->range3;
                $appraisalItemResult->score4 = $row->range4;
                $appraisalItemResult->score5 = $row->range5;
                $appraisalItemResult->target_value = $row->target;
                $appraisalItemResult->forecast_value = 0;
                $appraisalItemResult->actual_value = 0;
                $appraisalItemResult->percent_achievement = 0;
                $appraisalItemResult->max_value = $row->max_value;
                $appraisalItemResult->deduct_score_unit = $itemInfo["unit_deduct_score"];
                $appraisalItemResult->over_value = 0;
                $appraisalItemResult->value_get_zero = $row->value_get_zero;
                $appraisalItemResult->score = 0;
                $appraisalItemResult->threshold_group_id = $thresholdGroupId;
                $appraisalItemResult->weight_percent = (empty($row->weight)) ? "0": $row->weight;
                $appraisalItemResult->weigh_score = "0";
                $appraisalItemResult->structure_weight_percent = (empty($criteriaInfoQry)) ? "0" : $criteriaInfoQry[0]->weight_percent;
                $appraisalItemResult->created_by = Auth::id();
                $appraisalItemResult->updated_by = Auth::id();
                try {
    							$appraisalItemResult->save();
    						} catch (Exception $e) {
                  $errors[] = [
                    "title"=>"Sheet:".$sheetArr[$i],
                    "period_id"=>$row->period_id, "appraisal_type_id"=>$row->appraisal_type_id,
                    "level_id"=>$row->level_id, "org_id"=>$row->org_id, "emp_id"=>$row->emp_id,
                    "error_desc" => array(substr($e,0,254))
                  ];
                  $sheetError = true;
    						}
              } else {
                //---- Update @appraisal_item_result
                AppraisalItemResult::where(
                  'item_result_id', $existItemResultId
                )->update([
                  "emp_id" => $row->emp_id,
                  "position_id" => $row->position_id,
                  "item_name" => $row->appraisal_item_name,
                  "chief_emp_id" => $row->chief_emp_id,
                  "score0" => $row->range0,
                  "score1" => $row->range1,
                  "score2" => $row->range2,
                  "score3" => $row->range3,
                  "score4" => $row->range4,
                  "score5" => $row->range5,
                  "target_value" => $row->target,
                  "max_value" => $row->max_value,
                  "value_get_zero"=>$row->value_get_zero,
                  "threshold_group_id" => $thresholdGroupId,
                  "weight_percent" => (empty($row->weight)) ? "0": $row->weight,
                  //"weigh_score" => "0",
                  "structure_weight_percent" => (empty($criteriaInfoQry)) ? "0" : $criteriaInfoQry[0]->weight_percent,
                  "updated_by" => Auth::id(),
                  "updated_dttm" => date("Y-m-d H:i:s")
                ]);
              }
              // -- End -- Insert/Update @appraisal_item_result --------------//

            }//End Validate is true
          }//End Row


          // -- Start -- Check weight percent --------------------------------//
          $weightPercent = DB::select("
            SELECT air.period_id, air.emp_id, ai.structure_id, air.level_id, air.org_id,
              sum(air.weight_percent) weight_percent,
              max(air.structure_weight_percent) structure_weight_percent
            FROM appraisal_item_result air
            INNER JOIN appraisal_item ai ON ai.item_id = air.item_id
            INNER JOIN appraisal_structure str ON str.structure_id = ai.structure_id
            WHERE ai.structure_id = {$itemInfo["structure_id"]}
            AND str.structure_name = '{$sheetArr[$i]}'
            AND air.emp_result_id = {$currentEmpResultId}
            GROUP BY air.period_id, air.emp_id, ai.structure_id, air.level_id, air.org_id
            HAVING sum(air.weight_percent) > max(air.structure_weight_percent)
          ");
          if (!empty($weightPercent)) {
            foreach ($weightPercent as $wp) {
              $errors[] = [
                "title"=>"Sheet:".$sheetArr[$i],
                "period_id"=>$wp->period_id, "appraisal_type_id"=>"",
                "level_id"=>$wp->level_id, "org_id"=>$wp->org_id, "emp_id"=>$wp->emp_id,
                "error_desc" => Array("The percentage of overweight or not set.")
              ];
            }
            $sheetError = true;
          }
          // -- End -- Check weight percent --------------------------------//

          if ($sheetError) {
            // Something went wrong
            DB::rollback();
          } else {
            // All transaction good
            DB::commit();
          }

        }//End Sheet

        // -- Start -- Insert/Update @emp_result_stage -----------------------//
        $empResultStageId = 0;
        $empResultStage = DB::select("
          SELECT emp_result_id, stage_id
          FROM emp_result
          WHERE updated_dttm >= ?
        ", array($startFnDttm));

        foreach ($empResultStage as $value) {
          $empResultStageExist = DB::select("
            SELECT emp_result_stage_id
            FROM emp_result_stage
            WHERE emp_result_id = ?
          ", array($value->emp_result_id));

          //-- Checking if record exists in emp_result_stage.
          if (empty($empResultStageExist)) {
            //---- Insert @emp_result_stage
            $empResultStage = new EmpResultStage;
            $empResultStage->emp_result_id = $value->emp_result_id;
            $empResultStage->stage_id = $value->stage_id;
            $empResultStage->created_by = Auth::id();
            $empResultStage->updated_by = Auth::id();
            try {
              $empResultStage->save();
              $empResultStageId = $empResultStage->emp_result_stage_id;
            } catch (Exception $e) {
              $errors[] = [
                "title"=>"Table:emp_result_stage",
                "error_desc" => Array(substr($e,0,254))
              ];
            }
          } else {
            //---- Update @emp_result_stage
            // EmpResultStage::where(
              // 'emp_result_id', $value->emp_result_id
            // )->update([
              // "stage_id" => $value->stage_id,
              // "updated_by" => Auth::id(),
              // "updated_dttm" => date("Y-m-d H:i:s")
            // ]);
          }
        }
        // -- End -- Insert/Update @emp_result_stage -------------------------//
      }//End File

      $retVal = (empty($errors)) ? $status_ = 200 : $status_ = 400 ;

  		return response()->json(['status' => $status_, 'errors' => $errors]);
    }
}
