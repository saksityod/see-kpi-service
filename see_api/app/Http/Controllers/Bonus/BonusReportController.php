<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\SystemConfiguration;
use App\AppraisalLevel;
use App\Org;
use Auth;
use DB;
use Validator;
use Exception;
use Log;


class BonusReportController extends Controller
{
    public function __construct()
	  {
        //$this->middleware('jwt.auth');
    }

    public function test(Request $request)
    {
      // $org_name = DB::select("select org_name from org");
      //
      // return response()->json($org_name);
      $period_id = json_decode($request->period_id);
      $emp_id = json_decode($request->emp_id);
      $position_id = json_decode($request->position_id);

      $data = DB::select("select e.emp_name, po.position_name, p.appraisal_period_desc
        from emp_result em
        left join employee e on em.emp_id = e.emp_id
        left join appraisal_period p  on em.period_id = p.period_id
        left join position po on em.position_id = po.position_id
        where em.emp_id = ".$emp_id."
        and em.period_id = ".$period_id."
        and em.position_id = ".$position_id."");

      return response()->json($data);

    }

    public function index(Request $request){

      $period_id = json_decode($request->period_id);
      $appraisal_year = json_decode($request->appraisal_year);

      $AllOrg = DB::select("SELECT oo.org_id as parent_org_id
        , oo.org_code as parent_org_code
        , oo.org_name as parent_org_name
        , o.org_id, o.org_code, o.org_name
        FROM org oo
        LEFT JOIN org o ON o.parent_org_code = oo.org_code
        INNER JOIN appraisal_level le ON oo.level_id = le.level_id
        WHERE le.is_start_cal_bonus = 1
        ORDER BY oo.org_code ASC, o.org_code ASC");

      $Query_Information_Score = "
        SELECT emp.result_score as adjust_result_score
        , emp.org_id
        , emp.emp_result_id
        FROM emp_result_judgement er
        RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
        INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        WHERE FIND_IN_SET (emp.org_id , ?)
        AND pe.period_id = ?
        AND pe.appraisal_year = ?
        AND er.emp_result_judgement_id IS NULL
        UNION ALL
        SELECT er.adjust_result_score as adjust_result_score
        , emp.org_id
        , er.emp_result_id
        FROM emp_result_judgement er
        LEFT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
        INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        INNER JOIN (SELECT emp_result_id, max(created_dttm) max_create
        	FROM emp_result_judgement
        	GROUP BY emp_result_id) created ON er.created_dttm = created.max_create
        	AND er.emp_result_id = created.emp_result_id
        WHERE FIND_IN_SET (emp.org_id , ?)
        AND pe.period_id = ?
        AND pe.appraisal_year = ? ";


      $Query_CalculationState = "
        SELECT round(result.min,2) as min
        , round(result.max,2) as max
        , round(result.difference,2) as difference
        , round(avg(result.score_std),2) as std
        , round(result.mean,2) as mean
        , round(avg(case when result.odd = 1
        then
          (case when result.position_median = result.RowNumber then result.score else null end)
        else
          (case when result.position_median = result.RowNumber or (result.position_median-1) = result.RowNumber
          then result.score else null end)
        end),2) as median
        FROM
        (

          SELECT *,  @row_num := IF(@prev_value = rows.num ,@row_num+1 ,1) AS RowNumber
          , @prev_value := rows.num
          FROM (

            SELECT (1) AS num
            , re.emp_result_judgement_id
            , re.score
            , valuee.min
            , valuee.max
            , valuee.difference
            , valuee.mean
            , round(valuee.position_median,0) as position_median
            , valuee.odd
            , (re.score-valuee.mean) as score_std
            FROM
            (
              SELECT er.emp_result_id as emp_result_judgement_id
              , er.adjust_result_score as score
              FROM (".$Query_Information_Score.") er
              ORDER BY er.adjust_result_score ASC
            ) re
            CROSS JOIN
            (
              SELECT min(er.adjust_result_score) as min
              , max(er.adjust_result_score) as max
              , (max(er.adjust_result_score)-min(er.adjust_result_score)) as difference
              , avg(er.adjust_result_score) as mean
              , ((count(er.adjust_result_score)+1)/2) as position_median
              , (count(er.adjust_result_score)%2) as odd
              FROM (".$Query_Information_Score.") er

            ) valuee
            ORDER BY re.score ASC

          ) rows ,  (SELECT @row_num := 1) num_value,
           (SELECT @prev_value := '') set_value
        ) result";


    $Query_CalcutationMode = "
        SELECT information.num as frequency
        , (CASE WHEN information.num > 1
      	THEN COALESCE(GROUP_CONCAT(information.score,' '),'')
      	ELSE '-' END) as mode
        -- , COALESCE(GROUP_CONCAT(information.score,' '),'')
        FROM
        (
        		SELECT round(er.adjust_result_score,2) as score
        		, count(er.adjust_result_score) as num
        		FROM (".$Query_Information_Score.") er
        		GROUP BY er.adjust_result_score
        ) information
        INNER JOIN
        (
        		SELECT max(frequency.num) as max_frequency
        		FROM
        		(
        			SELECT count(er.adjust_result_score) as num
        			FROM (".$Query_Information_Score.") er
        			GROUP BY er.adjust_result_score
        		) frequency
        ) fre ON fre.max_frequency = information.num
        ORDER BY information.score ASC";


      foreach ($AllOrg as $org) {

        // หาลูกหลานของ parent_org_id
        if(!empty($org->parent_org_id)){
          $AllUnderParentOrg = $this->GetAllOrgCodeUnder($org->parent_org_id);
          $org->AllUnderParentOrg = $AllUnderParentOrg;
          $param_parent_org = $AllUnderParentOrg;
        } else {
          $org->AllUnderParentOrg = null;
          $param_parent_org = null;
        }

        // หาลูกหลานของ org_id
        if(!empty($org->org_id)){
          $AllUnderOrg = $this->GetAllOrgCodeUnder($org->org_id);
          $org->AllUnderOrg = $AllUnderOrg;
          $param_org = $AllUnderOrg;
        } else {
          $org->AllUnderOrg = null;
          $param_org = null;
        }

        // --------------------------คำนวนค่าของข้อมูลตาม parent_org_id-------------------------- //
        // คำนวนค่าทางสถิติ ตาม parent_org_id
        $ParentCalculationState = DB::select($Query_CalculationState
          ,array($param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year
          , $param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year));

        foreach ($ParentCalculationState as $ParentCalState) {
            $org->Parent_min = $ParentCalState->min;
            $org->Parent_max = $ParentCalState->max;
            $org->Parent_difference = $ParentCalState->difference;
            $org->Parent_std = $ParentCalState->std;
            $org->Parent_mean = $ParentCalState->mean;
            $org->Parent_median = $ParentCalState->median;
        }

        // หาค่าฐานนิยม ตาม parent_org_id
        $ParentCalculationMode = DB::select($Query_CalcutationMode
          ,array($param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year
          , $param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year));

        foreach ($ParentCalculationMode as $ParentCalMode) {
            $org->Parent_mode = $ParentCalMode->mode;
        }


        // --------------------------คำนวนค่าของข้อมูลตาม Org_id-------------------------- //
        // คำนวนค่าทางสถิติ ตาม Org_id
        $CalculationState = DB::select($Query_CalculationState
          ,array($param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year
          , $param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year));

        foreach ($CalculationState as $CalState) {
            $org->min = $CalState->min;
            $org->max = $CalState->max;
            $org->difference = $CalState->difference;
            $org->std = $CalState->std;
            $org->mean = $CalState->mean;
            $org->median = $CalState->median;
        }

        // หาค่าฐานนิยม ตาม Org_id
        $CalculationMode = DB::select($Query_CalcutationMode
          ,array($param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year
          , $param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year));

        foreach ($CalculationMode as $CalMode) {
            $org->mode = $CalMode->mode;
        }

      } //end foreach -> $AllOrg

      return ($AllOrg);
    }

    public function GetAllOrgCodeUnder($org_id)
    {
      // return ($org_id);
      $org_code = DB::select("SELECT org_code
          FROM org
          WHERE org_id = ".$org_id."");

      $parent = "";
      $place_parent = "";
      $have = true;

      foreach ($org_code as $o) {
        $parent = $o->org_code.",";
      }

      while($have){
        $org = DB::select("SELECT org_code
          FROM org
          WHERE FIND_IN_SET(parent_org_code,'".$parent."')
          AND parent_org_code != ''
        ");

        if(empty($org)) {
          $have = false;
          //  สิ้นสุดสาย Org
        }// end if
        else if (!empty($org)){
          $place_parent = $place_parent.$parent; // เก็บ Org_code ก่อนหน้าไว้ใน $place_parent
          $parent = "";

          foreach ($org as $o) {
            $parent = $parent.$o->org_code.",";
            // ข้อมูล Org_code ล่าสุดที่ได้จาก Query
          }
        }
      }// end else

      $place_parent = $place_parent.$parent; // เก็บ Org_code ล่าสุดที่หา Org_code ต่อไปไม่เจอ

      // นำ Org_code ไปหา Org_id ทั้งหมด
      $AllOrgID = DB::select("SELECT org_id
          FROM org
          WHERE FIND_IN_SET(org_code,'".$place_parent."')
      ");

      $AllOrg = "";     // เก็บ Org_id ในรูปแบบของ String
      foreach ($AllOrgID as $OrgID) {
        $AllOrg = $AllOrg.$OrgID->org_id.",";
      }

      return ($AllOrg);
      // return (['AllOrgCodeUnder' => $place_parent]);
    }
}



// $Query_CalcutationMode = "
//     SELECT information.num as frequency
//     , (CASE WHEN information.num > 1
//     THEN COALESCE(GROUP_CONCAT(information.score,' '),'')
//     ELSE '-' END) as mode
//     -- , COALESCE(GROUP_CONCAT(information.score,' '),'')
//     FROM
//     (
//         SELECT round(er.adjust_result_score,2) as score
//         , count(er.adjust_result_score) as num
//         FROM emp_result_judgement er
//         INNER JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//         INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//         WHERE FIND_IN_SET(emp.org_id, ?)
//         AND emp.period_id = ?
//         AND pe.appraisal_year = ?
//         GROUP BY er.adjust_result_score
//     ) information
//     INNER JOIN
//     (
//         SELECT max(frequency.num) as max_frequency
//         FROM
//         (
//           SELECT count(er.adjust_result_score) as num
//           FROM emp_result_judgement er
//           INNER JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//           INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//           WHERE FIND_IN_SET(emp.org_id, ?)
//           AND emp.period_id = ?
//           AND pe.appraisal_year = ?
//           GROUP BY er.adjust_result_score
//         ) frequency
//     ) fre ON fre.max_frequency = information.num
//     ORDER BY information.score ASC";



// $Query_CalculationState = "
//   SELECT round(result.min,2) as min
//   , round(result.max,2) as max
//   , round(result.difference,2) as difference
//   , round(avg(result.score_std),2) as std
//   , round(result.mean,2) as mean
//   , round(avg(case when result.odd = 1
//   then
//     (case when result.position_median = result.RowNumber then result.score else null end)
//   else
//     (case when result.position_median = result.RowNumber or (result.position_median-1) = result.RowNumber
//     then result.score else null end)
//   end),2) as median
//   FROM
//   (
//
//     SELECT *,  @row_num := IF(@prev_value = rows.num ,@row_num+1 ,1) AS RowNumber
//     , @prev_value := rows.num
//     FROM (
//
//       SELECT (1) AS num
//       , re.emp_result_judgement_id
//       , re.score
//       , valuee.min
//       , valuee.max
//       , valuee.difference
//       , valuee.mean
//       , round(valuee.position_median,0) as position_median
//       , valuee.odd
//       , (re.score-valuee.mean) as score_std
//       FROM
//       (
//         SELECT er.emp_result_judgement_id
//         , er.adjust_result_score as score
//         FROM emp_result_judgement er
//         INNER JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//         INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//         WHERE FIND_IN_SET(emp.org_id, ?)
//         AND emp.period_id = ?
//         AND pe.appraisal_year = ?
//         ORDER BY er.adjust_result_score ASC
//       ) re
//       CROSS JOIN
//       (
//         SELECT min(er.adjust_result_score) as min
//         , max(er.adjust_result_score) as max
//         , (max(er.adjust_result_score)-min(er.adjust_result_score)) as difference
//         , avg(er.adjust_result_score) as mean
//         , ((count(er.adjust_result_score)+1)/2) as position_median
//         , (count(er.adjust_result_score)%2) as odd
//         FROM emp_result_judgement er
//         INNER JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//         INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//         WHERE FIND_IN_SET(emp.org_id, ?)
//         AND emp.period_id = ?
//         AND pe.appraisal_year = ?
//
//       ) valuee
//       ORDER BY re.score ASC
//
//     ) rows ,  (SELECT @row_num := 1) num_value,
//      (SELECT @prev_value := '') set_value
//
//   ) result";
