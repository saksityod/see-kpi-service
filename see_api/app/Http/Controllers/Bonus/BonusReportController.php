<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\SystemConfiguration;
use App\AppraisalLevel;
use App\Org;
use Auth;
use DateTime;
use push;
use File;
use DB;
use Validator;
use Exception;
use Log;


class BonusReportController extends Controller
{
    public function __construct()
	  {
        $this->middleware('jwt.auth');
    }

    public function index(Request $request){

      $appraisal_year = json_decode($request->appraisal_year);
      $period_id = json_decode($request->period_id);
      $bu_org_id = json_decode($request->org_id);
      $param_user = json_decode($request->param_user);

      $AllOrg = DB::select("SELECT oo.org_id as parent_org_id
        , oo.org_code as parent_org_code
        , oo.org_name as parent_org_name
        , o.org_id, o.org_code, o.org_name
        , (SELECT appraisal_year FROM appraisal_period WHERE period_id = ".$period_id.") as appraisal_year
        , name_office.org_name as name_company
        FROM org oo
        -- LEFT JOIN org o ON o.parent_org_code = oo.org_code
        LEFT JOIN (
					 SELECT o.org_id, o.org_code, o.org_name, o.level_id, o.parent_org_code
					 FROM org o
					 INNER JOIN (SELECT org_id FROM emp_result WHERE period_id = ".$period_id." GROUP BY org_id) e_o
            ON e_o.org_id = o.org_id
				) o ON o.parent_org_code = oo.org_code
        INNER JOIN appraisal_level le ON oo.level_id = le.level_id
        CROSS JOIN (
            SELECT o.org_name FROM org o
            INNER JOIN appraisal_level le ON o.level_id = le.level_id
            ORDER BY le.seq_no ASC, o.org_id ASC
            LIMIT 1
				) name_office
        WHERE le.is_start_cal_bonus = 1
        AND FIND_IN_SET(oo.org_id, '".$bu_org_id."')
        AND o.org_id IS NOT NULL
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
        AND emp.appraisal_type_id = 2
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
        AND pe.appraisal_year = ?
        AND emp.appraisal_type_id = 2 ";


      $Query_CalculationState = "
        SELECT round(result.min,2) as min
        , round(result.max,2) as max
        , round(result.difference,2) as difference
        -- , round(avg(result.score_std),2) as std
        , round(SQRT(sum(result.score_std)/(count(result.score_std)-1)),2) as std
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
            , pow((re.score-valuee.mean),2) as score_std
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

      $Count_Staff_BU = "
        SELECT count(emp.emp_id)-? as num_emp
        FROM emp_result emp
        INNER JOIN appraisal_level le ON emp.level_id = le.level_id
        INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        WHERE emp.org_id = ?
        AND pe.period_id = ?
        AND pe.appraisal_year = ?
        AND emp.appraisal_type_id = 2 ";

      $Count_Staff_Division = "
        SELECT count(emp.emp_id)-? as num_emp
        FROM emp_result emp
        INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        WHERE FIND_IN_SET(emp.org_id, ?)
        AND pe.period_id = ?
        AND pe.appraisal_year = ?
        AND emp.appraisal_type_id = 2 ";

      $Org_Result_Score = "
        SELECT round(avg(orj.adjust_result_score),2) as org_result_score
        FROM org_result_judgement orj
        INNER JOIN appraisal_period pe ON orj.period_id = pe.period_id
        WHERE FIND_IN_SET(orj.org_id , ?)
        AND pe.period_id = ?
        AND pe.appraisal_year = ? ";

      $Before_After_Score = "
        SELECT round(sum(em.b_amount)/sum(FROM_BASE64(em.net_s_amount)),2) as before_bonus
        , round(sum(em.adjust_b_amount)/sum(FROM_BASE64(em.net_s_amount)),2) as after_bonus
        FROM emp_result em
        INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
        WHERE FIND_IN_SET(em.org_id, ?) -- by org
        AND pe.period_id = ?
        AND pe.appraisal_year = ?
        AND em.appraisal_type_id = 2 ";

      $Total_Before_After = "
          SELECT round(sum(em.b_amount)/sum(FROM_BASE64(em.net_s_amount)),2) as before_bonus
          , round(sum(em.adjust_b_amount)/sum(FROM_BASE64(em.net_s_amount)),2) as after_bonus
          FROM emp_result em
          INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
          WHERE pe.period_id = ?
          AND pe.appraisal_year = ?
          AND em.appraisal_type_id = 2 ";


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

        // หาค่าของข้อมูล ผจก.BU ตาม parent_org_id
        $org->ValueManagerBU = $this->ValueManagerOfOrg($org->parent_org_id, $period_id, $appraisal_year);

        // คำนวนค่าโบนัสก่อนแก้ไข และหลังแก้ไข ตาม parent_org_id
        $ParentBefore_After = DB::select($Before_After_Score
          ,array($org->parent_org_id, $period_id, $appraisal_year));

        foreach ($ParentBefore_After as $PBA) {
            $org->parent_before_bonus = $PBA->before_bonus;
            $org->parent_after_bonus = $PBA->after_bonus;
        }

        // หาจำนวน ผจก.BU ตาม parent_org_id
        $org->CountManagerBU = $this->CountManagerOrg($org->parent_org_id, $period_id, $appraisal_year);

        // หาจำนวน Staff ตาม parent_org_id
        $CountStaffByBU = DB::select($Count_Staff_BU
          ,array($org->CountManagerBU, $org->parent_org_id, $period_id, $appraisal_year));

        foreach ($CountStaffByBU as $CountStaffBU) {
            $org->CountStaffBU = $CountStaffBU->num_emp;
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

        // หาค่าของข้อมูล ผจก.ฝ่าย ตาม org_id
        $org->ValueManagerDivision = $this->ValueManagerOfOrg($org->org_id, $period_id, $appraisal_year);

        // คำนวนค่าโบนัสก่อนแก้ไข และหลังแก้ไข ตาม org_id
        $Before_After = DB::select($Before_After_Score
          ,array($org->org_id, $period_id, $appraisal_year));

        foreach ($Before_After as $BA) {
            $org->before_bonus = $BA->before_bonus;
            $org->after_bonus = $BA->after_bonus;
        }

        // หาจำนวน ผจก.ฝ่าย ตาม org_id
        $org->CountManagerDivision = $this->CountManagerOrg($org->org_id, $period_id, $appraisal_year);

        // หาจำนวน staff ตามลูกหลาน org_id
        $Count_Staff = DB::select($Count_Staff_Division
          ,array($org->CountManagerDivision, $param_org, $period_id, $appraisal_year));

        foreach ($Count_Staff as $CountStaff) {
            $org->CountStaffDivision = $CountStaff->num_emp;
        }

        // --------------------------Grand Total-------------------------- //
        // หา before, after ของทุกคนในบริษัท (รวมทุก org)
        $TotalBeforeAfter = DB::select($Total_Before_After
          ,array($period_id, $appraisal_year));

        foreach ($TotalBeforeAfter as $Total) {
            $org->Total_Before = $Total->before_bonus;
            $org->Total_After = $Total->after_bonus;
        }

      } //end foreach -> $AllOrg

      // นำข้อมูลทั้งหมดที่ได้ $AllOrg มาหาค่าผลรวมของ Staff และ ผจก.ฝ่าย
      $groups = array();
  		foreach ($AllOrg as $i) {
        $key = $i->parent_org_id;
  			if (!isset($groups[$key])) {
  				$groups[$key] = array(
  					'sum_manager' => $i->CountManagerDivision,
            'sum_staff' => $i->CountStaffDivision
  				);
  			} else {
  				$groups[$key]['sum_manager'] += $i->CountManagerDivision;
          $groups[$key]['sum_staff'] += $i->CountStaffDivision;
  			}
  		}

      // นำค่าผลรวมของ Staff(ที่รวม Staff ของ BU ด้วย) และ ผจก.ฝ่าย ใส่ลงในอาร์เรย์เดิม $AllOrg
      foreach ($AllOrg as $o) {
        $o->SumAllManager = $groups[$o->parent_org_id]['sum_manager'];
        $o->SumAllStaff = $groups[$o->parent_org_id]['sum_staff']+$o->CountStaffBU;
      }

      // return ($AllOrg);

      $now = new DateTime();
      $date = $now->format('Y-m-d_H-i-s');
      $data = json_encode($AllOrg);             // ใช้ $AllOrg เป็นข้อมูลภายในไฟล์ .JSON
      $namefile = 'BonusReportController_'.$date;
      $fileName = $namefile.'.json';
      File::put(base_path("resources/generate/").$fileName,$data);      // สร้างไฟล์ JSON บันทึกข้อมูลลง JSON ไฟล์
      if(file_exists(base_path("resources/generate/".$fileName))){      // ตรวจสอบว่าไฟล์ .JSON ได้มีการสร้างขึ้นรึยัง?
        return response()->json(['status' => 200, 'data' => $namefile]);      // ส่งชื่อไฟล์ .JSON ไปยัง Front
       }else{
        return response()->json(['status' => 400, 'data' => 'Generate File Not Success!']);
      }


      // รายงานสถิติวิเคราะห์สำหรับการประเมินผลงาน ประจำปี 20xx (บริษัท ดี เอช เอ สยามวาลา จำกัด)
      // คำนวณตาม org_id ของแต่ละ record
      //   •	ผจก.ฝ่าย (คำนวณ) : ค่าข้อมูลคนที่มี appraisal_level.seq_no น้อยที่สุด และมี emp_id น้อยที่สุด
      //   •	ผจก.BU (คำนวณ) : ค่าข้อมูลคนที่มี appraisal_level.seq_no น้อยที่สุด และมี emp_id น้อยที่สุด
      //   •	Staff (จำนวน) : จำนวนคนทั้งหมดที่ไม่ใช่ ผจก.ฝ่าย และผจก.BU
      //   •	ผจก.ฝ่าย (จำนวน) : นับจำนวนคนที่มี appraisal_level.seq_no น้อยที่สุด และมี emp_id น้อยที่สุด
      //   •	ผจก.BU (จำนวน) : นับจำนวนคนที่มี appraisal_level.seq_no น้อยที่สุด และมี emp_id น้อยที่สุด
      //   •	ก่อนแก้ไข (Bonus) : sum(b_amount)/sum(net_s_amount)
      //   •	หลังแก้ไข (Bonus) : sum(adjust_b_amount)/sum(net_s_amount)
      // คำนวณตาม org_id ของลูกหลานตามแต่ละ record
      //   •	Min
      //   •	Max
      //   •	ผลต่าง : Max-Min
      //   •	STD (ส่วนเบี่ยงเบนมาตรฐาน): {sum [(ข้อมูลดิบ-Mean)ยกกำลังสอง]}/(จำนวนข้อมูลทั้งหมด-1)
      //   •	Mean : avg
      //   •	Median (ค่ากลาง) : [ตำแหน่ง : (จำนวนข้อมูลทั้งหมด+1)/2] : นำตำแหน่งไปหาข้อมูล ณ ตำแหน่งนั้น
      //      หากตำแหน่งเป็น 2.5 ให้นำข้อมูลตำแหน่ง 2 บวกข้อมูลตำแหน่ง 3 แล้วนำมาหาร 2
      //   •	Mode : ข้อมูลที่ซ้ำกันมากที่สุด หากไม่มีข้อมูลที่ซ้ำกันจะถือว่าไม่มีฐานนิยม
      //   •	ฝ่าย (คำนวณ) : avg(org_result_judgement.adjust_result_score)
      //   •	BU (คำนวณ) : avg(org_result_judgement.adjust_result_score)

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
      $AllOrgID = DB::select("SELECT distinct org_id
          FROM org
          WHERE FIND_IN_SET(org_code,'".$place_parent."')
      ");

      $AllOrg = "";     // เก็บ Org_id ในรูปแบบของ String
      foreach ($AllOrgID as $OrgID) {
        $AllOrg = $AllOrg.$OrgID->org_id.",";
      }

      return ($AllOrg);
      // return (['AllOrgCodeUnder' => $place_parent]);


      // ขั้นตอนหาค่า org ที่อยู่ภายใต้ org ที่ต้องการ (org ที่เป็นลูกหลานทั้งหมด)
      // 1.หาชื่อ org_code จาก org_id
      // 2.เก็บ org_code ที่หาได้ (แม่) ไว้ใน parent
      //   •	วนหา org_code ที่มี parent_org_code เป็น parent
      //   •	หากไม่มีลูกของ parent จึงกำหนดให้หยุดการวน loop
      //   •	หากมีลูกของ parent ให้นำ parent เก็บไว้ใน place_parent
      //   •	แล้วกำหนดให้ parent เป็นค่าว่าง (เพื่อให้สามารถเก็บข้อมูลลูกที่พึ่งหาได้)
      //   •	กำหนด parent เป็น org_code ของลูกที่พึ่งหาได้
      //   •	แล้วให้ทำการวน loop แบบนี้ไปเรื่อยๆจนกว่าจะหาลูกต่อไปไม่เจอ แล้วจึงให้หยุดการวน loop
      // 3.เก็บ org_code ที่ใช้ในการหาลูกก่อนจะทำให้หยุดการวน loop ไว้ใน place_parent
      // 4.นำ place_parent หา org_id

    }

    public function CountManagerOrg($org_id, $period_id, $appraisal_year){

      // $appraisal_year = json_decode($request->appraisal_year);
      // $period_id = json_decode($request->period_id);
      // $org_id = json_decode($request->org_id);

      if(empty($org_id)){
        return(0);
      }

      $min_seq = DB::select("
        SELECT le.seq_no, count(emp.emp_id) as num_emp
        FROM emp_result emp
        INNER JOIN appraisal_level le ON emp.level_id = le.level_id
        INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        WHERE emp.org_id = ".$org_id."
        AND pe.period_id = ".$period_id."
        AND pe.appraisal_year = ".$appraisal_year."
        AND emp.appraisal_type_id = 2
        AND emp.emp_id IS NOT NULL
        GROUP BY le.seq_no
        ORDER BY le.seq_no ASC
        LIMIT 1");

      if(empty($min_seq)){
        return(0);
      } else if ($min_seq[0]->num_emp == 1){
        return(1);
      } else {
        $manager_org = "
          SELECT emp.emp_id, emp.chief_emp_id
        	FROM emp_result emp
        	INNER JOIN appraisal_level le ON emp.level_id = le.level_id
        	INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
        	WHERE emp.org_id = ".$org_id."
        	AND pe.period_id = ".$period_id."
        	AND pe.appraisal_year = ".$appraisal_year."
        	AND le.seq_no = ".$min_seq[0]->seq_no."
        	AND emp.appraisal_type_id = 2
          AND emp.emp_id IS NOT NULL
          LIMIT 1";

        $manager = DB::select("
          SELECT count(manager.emp_id) as num_emp
          FROM( ".$manager_org.") manager
          ");

        // $manager = DB::select("
        //   SELECT count(manager.emp_id) as num_emp FROM(
        //   	SELECT re.emp_id FROM(".$manager_org.") re
        //   	INNER JOIN (".$manager_org.") result ON re.emp_id = result.chief_emp_id
        //   ) manager
        //   ");

        return($manager[0]->num_emp);
      }

      // return response()->json($min_seq);

    }

    public function ValueManagerOfOrg($org_id, $period_id, $appraisal_year){

      //$org_id, $period_id, $appraisal_year

      // $appraisal_year = json_decode($request->appraisal_year);
      // $period_id = json_decode($request->period_id);
      // $org_id = json_decode($request->org_id);

      if(empty($org_id)){
        return (null);
      }

      $main_query = "
        SELECT le.seq_no, emp.emp_id, emp_result_id, emp.chief_emp_id
    		FROM emp_result emp
    		INNER JOIN appraisal_level le ON emp.level_id = le.level_id
    		INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
    		WHERE emp.org_id = ".$org_id."
    		AND pe.period_id = ".$period_id."
    		AND pe.appraisal_year = ".$appraisal_year."
        AND emp.emp_id IS NOT NULL
    		AND emp.appraisal_type_id = 2 ";
      // return response()->json($min_seq);

      // หาจำนวนคนที่มีใน org นั้น
      $Count = DB::select("
        SELECT count(result.emp_id) as num_emp
        FROM(".$main_query.") result");

      // return response()->json($Count);


      if($Count[0]->num_emp == 0){
        $EmpManagerID = NULL;
      }else if ($Count[0]->num_emp == 1){

        // หา emp_id ของคนที่เป็น ผจก.
        $EmpInOrg = DB::select($main_query);
        $EmpManagerID = $EmpInOrg[0]->emp_id;

      }else {

          // หา seq_no ที่น้อยสุดสำหรับ org นี้
          $MinSeqNO = DB::select("
            SELECT min(result.seq_no) as min_seq_no
            FROM(".$main_query.") result");

          //นับจำนวน emp ที่มีค่า seq_no น้อยที่สุด
          $CountMinSeqNo = DB::select("
            SELECT count(result.emp_id) as num_emp
            FROM(".$main_query."
          		AND le.seq_no = ".$MinSeqNO[0]->min_seq_no."
          	) result");

          if($CountMinSeqNo[0]->num_emp == 1){

            // หา emp_id ของคนที่มี seq_no น้อยที่สุด
            $EmpMinSeqNo = DB::select("
              SELECT result.emp_id
              FROM(".$main_query."
            		AND le.seq_no = ".$MinSeqNO[0]->min_seq_no."
            	) result");

            $EmpManagerID = $EmpMinSeqNo[0]->emp_id;

          } else {

            // หา emp_id ที่น้อยที่สุดที่มี seq_no น้อยที่สุด
            $ManagerMinSeqNo = DB::select(
              $main_query.
              "AND le.seq_no = ".$MinSeqNO[0]->min_seq_no."
              ORDER BY emp.emp_id ASC
              LIMIT 1 ");

            $EmpManagerID = $ManagerMinSeqNo[0]->emp_id;

            // "SELECT re.emp_id
            //   FROM(".$main_query."
            //     AND le.seq_no = ".$MinSeqNO[0]->min_seq_no.") re
            //   INNER JOIN (".$main_query."
            //     AND le.seq_no = ".$MinSeqNO[0]->min_seq_no."
            //   ) result ON re.emp_id = result.chief_emp_id"

            // $EmpManagerID = "";
            // foreach ($ManagerMinSeqNo as $EmpManager) {
            //   $EmpManagerID = $EmpManagerID.$EmpManager->emp_id.",";
            // }
          }
      }

      $ValueManagerOrg = DB::select("
        SELECT round(avg(result.adjust_result_score),2) as adjust_result_score
        FROM(
            SELECT emp.result_score as adjust_result_score
            , emp.org_id
            , emp.emp_result_id
            FROM emp_result_judgement er
            RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
            INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
            WHERE FIND_IN_SET (emp.org_id , '".$org_id."')
            AND pe.period_id = ".$period_id."
            AND pe.appraisal_year = ".$appraisal_year."
    				AND FIND_IN_SET(emp.emp_id, '".$EmpManagerID."')
            AND er.emp_result_judgement_id IS NULL
            AND emp.appraisal_type_id = 2
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
            WHERE FIND_IN_SET (emp.org_id , '".$org_id."')
            AND pe.period_id = ".$period_id."
            AND pe.appraisal_year = ".$appraisal_year."
    				AND FIND_IN_SET(emp.emp_id, '".$EmpManagerID."')
            AND emp.appraisal_type_id = 2
          ) result ");

      foreach ($ValueManagerOrg as $VMScore) {
          $ValueManager = $VMScore->adjust_result_score;
      }

      return($ValueManager);

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


// ----------------คำนวนจำนวนข้อมูล ตาม parent_org_id และ org_id---------------- //
// คำนวนจำนวน ผจก.BU ตาม parent_org_id
// $CountManagerBU = DB::select("
//   SELECT count(em.emp_id) as bu
//   FROM emp_result em
//   INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
//   INNER JOIN appraisal_level le ON em.level_id = le.level_id
//   WHERE FIND_IN_SET(em.org_id, ?)  -- parent_org
//   AND pe.period_id = ?
//   AND pe.appraisal_year = ?
//   AND le.is_start_cal_bonus = 1
//   AND em.appraisal_type_id = 2 "
// , array($org->parent_org_id, $period_id, $appraisal_year));
//
// foreach ($CountManagerBU as $CountBU) {
//     $org->CountBU = $CountBU->bu;
// }

// คำนวนจำนวน ผจก.ฝ่าย และ Staff ตาม parent_org_id
// $CountParentManagerDivision_Staff = DB::select("
//   SELECT count(re.division) as parent_division
//   , count(re.staff) as parent_staff
//   FROM
//   (
//   	SELECT (CASE WHEN le_parent.is_start_cal_bonus = 1 THEN em.emp_id ELSE null END) as division
//   	, (CASE WHEN le_parent.is_start_cal_bonus is null THEN em.emp_id ELSE null END) as staff
//   	, em.org_id, o_parent.org_id as parent_org_id
//   	FROM emp_result em
//   	INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
//   	INNER JOIN appraisal_level le ON em.level_id = le.level_id
//   	LEFT JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
//   	INNER JOIN org o ON em.org_id = o.org_id
//   	LEFT JOIN org o_parent ON o_parent.org_code = o.parent_org_code
//   	WHERE FIND_IN_SET(o_parent.org_id, ?)  -- parent_org
//   	AND pe.period_id = ?
//   	AND pe.appraisal_year = ?
//   	AND le.is_start_cal_bonus is null
//     AND em.appraisal_type_id = 2
//   ) re"
// , array($org->parent_org_id, $period_id, $appraisal_year));
//
// foreach ($CountParentManagerDivision_Staff as $CountParentStaff) {
//     $org->Countparent_division = $CountParentStaff->parent_division;
//     $org->Countparent_staff = $CountParentStaff->parent_staff;
// }

// คำนวนจำนวน ผจก.ฝ่าย และ Staff ตาม org_id
// $CountManagerDivision_Staff = DB::select("
//   SELECT count(re.division) as division
//   , count(re.staff) as staff
//   FROM
//   (
//   	SELECT (CASE WHEN le_parent.is_start_cal_bonus = 1 THEN em.emp_id ELSE null END) as division
//   	, (CASE WHEN le_parent.is_start_cal_bonus is null THEN em.emp_id ELSE null END) as staff
//   	FROM emp_result em
//   	INNER JOIN appraisal_period pe ON em.period_id = pe.period_id
//   	INNER JOIN appraisal_level le ON em.level_id = le.level_id
//   	LEFT JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
//   	WHERE FIND_IN_SET(em.org_id, ?) -- org
//   	AND pe.period_id = ?
//   	AND pe.appraisal_year = ?
//   	AND le.is_start_cal_bonus is null
//     AND em.appraisal_type_id = 2
//   ) re"
// , array($org->org_id, $period_id, $appraisal_year));
//
// foreach ($CountManagerDivision_Staff as $CountStaff) {
//     $org->CountDivision = $CountStaff->division;
//     $org->CountStaff = $CountStaff->staff;
// }


// คำนวนค่า ผจก.BU ตาม parent_org_id
// $CalculationManagerBU = DB::select("
//   SELECT round(avg(result.result_score),2) as score_bu_manager
//   FROM
//   (
//     SELECT emp.result_score as result_score
//     , emp.org_id
//     , emp.emp_result_id
//     , le.level_id
//     FROM emp_result_judgement er
//     RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//     INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//     INNER JOIN appraisal_level le ON emp.level_id = le.level_id
//     WHERE FIND_IN_SET (emp.org_id , ?)
//     AND pe.period_id = ?
//     AND pe.appraisal_year = ?
//     AND le.is_start_cal_bonus = 1
//     AND er.emp_result_judgement_id IS NULL
//     AND emp.appraisal_type_id = 2
//     UNION ALL
//     SELECT er.adjust_result_score as result_score
//     , emp.org_id
//     , er.emp_result_id
//     , le.level_id
//     FROM emp_result_judgement er
//     LEFT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//     INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//     INNER JOIN appraisal_level le ON emp.level_id = le.level_id
//     INNER JOIN (SELECT emp_result_id, max(created_dttm) max_create
//       FROM emp_result_judgement
//       GROUP BY emp_result_id) created ON er.created_dttm = created.max_create
//       AND er.emp_result_id = created.emp_result_id
//     WHERE FIND_IN_SET (emp.org_id , ?)
//     AND pe.period_id = ?
//     AND pe.appraisal_year = ?
//     AND le.is_start_cal_bonus = 1
//     AND emp.appraisal_type_id = 2
//   ) result"
// , array($org->parent_org_id, $period_id, $appraisal_year, $org->parent_org_id, $period_id, $appraisal_year));
//
// foreach ($CalculationManagerBU as $ManagerBU) {
//     $org->ManagerBU = $ManagerBU->score_bu_manager;
// }



// คำนวนค่า ผจก.ฝ่าย ตาม org_id
// $CalculationManagerDivision = DB::select("
//   SELECT round(avg(result.result_score),2) as score_division_manager
//   FROM
//   (
//     SELECT emp.result_score as result_score
//     , emp.org_id
//     , emp.emp_result_id
//     , le.level_id
//     , le_parent.level_id as parent_id
//     FROM emp_result_judgement er
//     RIGHT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//     INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//     INNER JOIN appraisal_level le ON emp.level_id = le.level_id
//     INNER JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
//     WHERE FIND_IN_SET (emp.org_id , ?)
//     AND pe.period_id = ?
//     AND pe.appraisal_year = ?
//     AND le_parent.is_start_cal_bonus = 1
//     AND er.emp_result_judgement_id IS NULL
//     AND emp.appraisal_type_id = 2
//     UNION ALL
//     SELECT er.adjust_result_score as result_score
//     , emp.org_id
//     , er.emp_result_id
//     , le.level_id
//     , le_parent.level_id as parent_id
//     FROM emp_result_judgement er
//     LEFT JOIN emp_result emp ON er.emp_result_id = emp.emp_result_id
//     INNER JOIN appraisal_period pe ON emp.period_id = pe.period_id
//     INNER JOIN appraisal_level le ON emp.level_id = le.level_id
//     INNER JOIN appraisal_level le_parent ON le_parent.level_id = le.parent_id
//     INNER JOIN (SELECT emp_result_id, max(created_dttm) max_create
//       FROM emp_result_judgement
//       GROUP BY emp_result_id) created ON er.created_dttm = created.max_create
//       AND er.emp_result_id = created.emp_result_id
//     WHERE FIND_IN_SET (emp.org_id , ?)
//     AND pe.period_id = ?
//     AND pe.appraisal_year = ?
//     AND le_parent.is_start_cal_bonus = 1
//     AND emp.appraisal_type_id = 2
//   ) result"
// , array($org->org_id, $period_id, $appraisal_year, $org->org_id, $period_id, $appraisal_year));
//
// foreach ($CalculationManagerDivision as $ManagerDivision) {
//     $org->ManagerDivision = $ManagerDivision->score_division_manager;
// }
