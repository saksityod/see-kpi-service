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

      return ($emp_id);

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

      $appraisal_year = json_decode($request->appraisal_year);
      $period_id = json_decode($request->period_id);
      $bu_org_id = json_decode($request->org_id);

      $AllOrg = DB::select("SELECT oo.org_id as parent_org_id
        , oo.org_code as parent_org_code
        , oo.org_name as parent_org_name
        , o.org_id, o.org_code, o.org_name
        , (SELECT appraisal_year FROM appraisal_period WHERE period_id = ".$period_id.") as appraisal_year
        FROM org oo
        LEFT JOIN org o ON o.parent_org_code = oo.org_code
        INNER JOIN appraisal_level le ON oo.level_id = le.level_id
        WHERE le.is_start_cal_bonus = 1
        AND FIND_IN_SET(oo.org_id, '".$bu_org_id."')
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

      $Org_Result_Score = "
        SELECT round(avg(orj.adjust_result_score),2) as org_result_score
        FROM org_result_judgement orj
        INNER JOIN appraisal_period pe ON orj.period_id = pe.period_id
        WHERE FIND_IN_SET(orj.org_id , ?)
        AND pe.period_id = ?
        AND pe.appraisal_year = ? ";

      $Before_After_Score = "
        SELECT round(sum(em.b_amount)/sum(em.net_s_amount),2) as before_bonus
        , round(sum(em.adjust_b_amount)/sum(em.net_s_amount),2) as after_bonus
        FROM emp_result em
        INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
        WHERE FIND_IN_SET(em.org_id, ?) -- by org
        AND pe.period_id = ?
        AND pe.appraisal_year = ?";


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
        // คำนวนค่าทางสถิติ ตามลูกหลาน parent_org_id
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

        // หาค่าฐานนิยม ตามลูกหลาน parent_org_id
        $ParentCalculationMode = DB::select($Query_CalcutationMode
          ,array($param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year
          , $param_parent_org, $period_id, $appraisal_year, $param_parent_org, $period_id, $appraisal_year));

        foreach ($ParentCalculationMode as $ParentCalMode) {
            $org->Parent_mode = $ParentCalMode->mode;
        }

        // คำนวนค่า BU ตามลูกหลาน parent_org_id
        $CalculationBU = DB::select($Org_Result_Score
          ,array($param_parent_org, $period_id, $appraisal_year));

        foreach ($CalculationBU as $CalBU) {
            $org->CalBU = $CalBU->org_result_score;
        }

        // คำนวนค่า ผจก.BU ตาม parent_org_id
        $CalculationManagerBU = DB::select("
          SELECT round(avg(result.result_score),2) as score_bu_manager
          FROM
          (
          	SELECT emp.result_score as result_score
          	, emp.org_id
          	, emp.emp_result_id
          	, le.level_id
          	FROM emp_result_judgement er
          	RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
          	INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON emp.level_id = le.level_id
          	WHERE FIND_IN_SET (emp.org_id , ?)
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le.is_start_cal_bonus = 1
          	AND er.emp_result_judgement_id IS NULL
          	UNION ALL
          	SELECT er.adjust_result_score as result_score
          	, emp.org_id
          	, er.emp_result_id
          	, le.level_id
          	FROM emp_result_judgement er
          	LEFT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
          	INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON emp.level_id = le.level_id
          	INNER JOIN (SELECT emp_result_id, max(created_dttm) max_create
          		FROM emp_result_judgement
          		GROUP BY emp_result_id) created ON er.created_dttm = created.max_create
          		AND er.emp_result_id = created.emp_result_id
          	WHERE FIND_IN_SET (emp.org_id , ?)
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le.is_start_cal_bonus = 1
          ) result"
        , array($org->parent_org_id, $period_id, $appraisal_year, $org->parent_org_id, $period_id, $appraisal_year));

        foreach ($CalculationManagerBU as $ManagerBU) {
            $org->ManagerBU = $ManagerBU->score_bu_manager;
        }

        // คำนวนค่าโบนัสก่อนแก้ไข และหลังแก้ไข ตาม org_id
        $ParentBefore_After = DB::select($Before_After_Score
          ,array($org->parent_org_id, $period_id, $appraisal_year));

        foreach ($ParentBefore_After as $PBA) {
            $org->parent_before_bonus = $PBA->before_bonus;
            $org->parent_after_bonus = $PBA->after_bonus;
        }


        // --------------------------คำนวนค่าของข้อมูลตาม Org_id-------------------------- //
        // คำนวนค่าทางสถิติ ตามลูกหลาน Org_id
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

        // หาค่าฐานนิยม ตามลูกหลาน Org_id
        $CalculationMode = DB::select($Query_CalcutationMode
          ,array($param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year
          , $param_org, $period_id, $appraisal_year, $param_org, $period_id, $appraisal_year));

        foreach ($CalculationMode as $CalMode) {
            $org->mode = $CalMode->mode;
        }

        // คำนวนค่า ฝ่าย ตามลูกหลาน org_id
        $CalculationDivision = DB::select($Org_Result_Score
          ,array($param_org, $period_id, $appraisal_year));

        foreach ($CalculationDivision as $CalDivision) {
            $org->CalDivision = $CalDivision->org_result_score;
        }

        // คำนวนค่า ผจก.ฝ่าย ตาม org_id
        $CalculationManagerDivision = DB::select("
          SELECT round(avg(result.result_score),2) as score_division_manager
          FROM
          (
          	SELECT emp.result_score as result_score
          	, emp.org_id
          	, emp.emp_result_id
          	, le.level_id
          	, le_parent.level_id as parent_id
          	FROM emp_result_judgement er
          	RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
          	INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON emp.level_id = le.level_id
          	INNER JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
          	WHERE FIND_IN_SET (emp.org_id , ?)
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le_parent.is_start_cal_bonus = 1
          	AND er.emp_result_judgement_id IS NULL
          	UNION ALL
          	SELECT er.adjust_result_score as result_score
          	, emp.org_id
          	, er.emp_result_id
          	, le.level_id
          	, le_parent.level_id as parent_id
          	FROM emp_result_judgement er
          	LEFT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
          	INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON emp.level_id = le.level_id
          	INNER JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
          	INNER JOIN (SELECT emp_result_id, max(created_dttm) max_create
          		FROM emp_result_judgement
          		GROUP BY emp_result_id) created ON er.created_dttm = created.max_create
          		AND er.emp_result_id = created.emp_result_id
          	WHERE FIND_IN_SET (emp.org_id , ?)
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le_parent.is_start_cal_bonus = 1
          ) result"
        , array($org->org_id, $period_id, $appraisal_year, $org->org_id, $period_id, $appraisal_year));

        foreach ($CalculationManagerDivision as $ManagerDivision) {
            $org->ManagerDivision = $ManagerDivision->score_division_manager;
        }

        // คำนวนค่าโบนัสก่อนแก้ไข และหลังแก้ไข ตาม org_id
        $Before_After = DB::select($Before_After_Score
          ,array($org->org_id, $period_id, $appraisal_year));

        foreach ($Before_After as $BA) {
            $org->before_bonus = $BA->before_bonus;
            $org->after_bonus = $BA->after_bonus;
        }


        // ----------------คำนวนจำนวนข้อมูล ตาม parent_org_id และ org_id---------------- //
        // คำนวนจำนวน ผจก.BU ตาม parent_org_id
        $CountManagerBU = DB::select("
          SELECT count(em.emp_id) as bu
          FROM emp_result em
          INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
          INNER JOIN appraisal_level le ON em.level_id = le.level_id
          WHERE FIND_IN_SET(em.org_id, ?)  -- parent_org
          AND pe.period_id = ?
          AND pe.appraisal_year = ?
          AND le.is_start_cal_bonus = 1"
        , array($org->parent_org_id, $period_id, $appraisal_year));

        foreach ($CountManagerBU as $CountBU) {
            $org->CountBU = $CountBU->bu;
        }

        // คำนวนจำนวน ผจก.ฝ่าย และ Staff ตาม parent_org_id
        $CountParentManagerDivision_Staff = DB::select("
          SELECT count(re.division) as parent_division
          , count(re.staff) as parent_staff
          FROM
          (
          	SELECT (CASE WHEN le_parent.is_start_cal_bonus = 1 THEN em.emp_id ELSE null END) as division
          	, (CASE WHEN le_parent.is_start_cal_bonus is null THEN em.emp_id ELSE null END) as staff
          	, em.org_id, o_parent.org_id as parent_org_id
          	FROM emp_result em
          	INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON em.level_id = le.level_id
          	LEFT JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
          	INNER JOIN org o ON em.org_id = o.org_id
          	LEFT JOIN org o_parent ON o_parent.org_code = o.parent_org_code
          	WHERE FIND_IN_SET(o_parent.org_id, ?)  -- parent_org
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le.is_start_cal_bonus is null
          ) re"
        , array($org->parent_org_id, $period_id, $appraisal_year));

        foreach ($CountParentManagerDivision_Staff as $CountParentStaff) {
            $org->Countparent_division = $CountParentStaff->parent_division;
            $org->Countparent_staff = $CountParentStaff->parent_staff;
        }

        // คำนวนจำนวน ผจก.ฝ่าย และ Staff ตาม org_id
        $CountManagerDivision_Staff = DB::select("
          SELECT count(re.division) as division
          , count(re.staff) as staff
          FROM
          (
          	SELECT (CASE WHEN le_parent.is_start_cal_bonus = 1 THEN em.emp_id ELSE null END) as division
          	, (CASE WHEN le_parent.is_start_cal_bonus is null THEN em.emp_id ELSE null END) as staff
          	FROM emp_result em
          	INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
          	INNER JOIN appraisal_level le ON em.level_id = le.level_id
          	LEFT JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
          	WHERE FIND_IN_SET(em.org_id, ?) -- org
          	AND pe.period_id = ?
          	AND pe.appraisal_year = ?
          	AND le.is_start_cal_bonus is null
          ) re"
        , array($org->org_id, $period_id, $appraisal_year));

        foreach ($CountManagerDivision_Staff as $CountStaff) {
            $org->CountDivision = $CountStaff->division;
            $org->CountStaff = $CountStaff->staff;
        }

        // ----------------คำนวนจำนวนข้อมูล ตาม parent_org_id และ org_id---------------- //

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
