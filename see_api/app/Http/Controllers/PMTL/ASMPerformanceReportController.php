<?php

namespace App\Http\Controllers\PMTL;

use Illuminate\Http\Request;

use Log;
use DB;
use App\Http\Requests;
use App\Http\Controllers\Controller;

class ASMPerformanceReportController extends Controller
{
    public function __construct()
    {
        // $this->middleware('jwt.auth');
    }

    public function index(Request $request)
    {

        $param_year = $request->year; // 2019
        $param_questionaire_type_id = json_decode($request->questionaire_type_id); // "1,2"

        //----------------------------------[Start] Query ของชุดข้อมูลตามจริง ทำขึ้นเพื่อใช้ในการจัดการข้อมูล -----------------------------------------------
        $position_headcount = "
          SELECT h.position_id
          , po.position_name
          , h.valid_date as start_valid_date
          , COALESCE(
          	(SELECT hc.valid_date-INTERVAL 1 DAY
          	FROM head_count hc
          	WHERE hc.valid_date > h.valid_date
          	ORDER BY hc.valid_date ASC
          	LIMIT 1)
          	, CONCAT(2019,'-12-31')
          ) as end_valid_date
          , md.max_date
          , jf.job_function_group_id
          , (CASE WHEN jf.job_function_group_id = 1 THEN SUM(h.head_count) ELSE 0 END) as MGR
					, (CASE WHEN jf.job_function_group_id = 2 THEN SUM(h.head_count) ELSE 0 END) as FSF
					, (CASE WHEN jf.job_function_group_id = 3 THEN SUM(h.head_count) ELSE 0 END) as VSM
          , SUM(h.head_count) as head_count
          FROM head_count h
          INNER JOIN job_function jf ON h.job_function_id = jf.job_function_id
          INNER JOIN position po ON po.position_id = h.position_id
          CROSS JOIN (
  					SELECT MAX(valid_date) as max_date
  					FROM head_count
  					WHERE YEAR(valid_date) = ".$param_year."
					) as md
          WHERE YEAR(h.valid_date) = ".$param_year."
          GROUP BY h.position_id
          , po.position_name
          , h.valid_date
          , md.max_date
          , jf.job_function_group_id ";

        // จัด group เพื่อจัดการข้อมูล column ใหม่คือ MGR,FSF,VSM
        // หา employee ที่มี start_date อยู่ระหว่าง start_valid_date, end_valid_date ตาม position_id
        $group_position_headcount = "
          SELECT field.position_id
          , field.position_name
          , field.start_valid_date
          , field.end_valid_date
          , field.max_date
          , (SELECT GROUP_CONCAT(em.emp_snapshot_id)
            FROM employee_snapshot em
            WHERE field.position_id = em.position_id
            AND em.start_date BETWEEN field.start_valid_date AND field.end_valid_date
          ) as emp_snapshot_id
          , MAX(field.MGR) MGR
          , MAX(field.FSF) FSF
          , MAX(field.VSM) VSM
          FROM (".$position_headcount.") field
          GROUP BY field.position_id
          , field.position_name
          , field.start_valid_date
          , field.end_valid_date
          , field.max_date ";

        // หาจำนวน questionaire_type ตามพารามิเตอร์
        $questionaire_type = DB::select("
          SELECT questionaire_type_id as type_id
          , questionaire_type as type_name
          FROM questionaire_type
          WHERE FIND_IN_SET(questionaire_type_id,'".$param_questionaire_type_id."' )");

          // หาลูกทั้งหมดตาม period ทั้งหมดที่มีใน emp_under
          $emp_under = DB::select($group_position_headcount);
          $groups_emp_under = [];
          foreach ($emp_under as $eu) {
            // ข้อมูลหลัก
            $eu->under_emp = $this->GetAllEmpCodeUnder($eu->emp_snapshot_id ,$eu->start_valid_date ,$eu->end_valid_date, $param_questionaire_type_id);

            /* function(CalculatePercent) เรียงตามลำดับพารามิเตอร์
              $under_emp : ลูกน้องภายในทั้งหมดภายในสาย
              $job_group : job_function_group_id ที่ต้องการหาข้อมูล
              $start_valid_date : วันที่เริ่มต้น
              $end_valid_date : วันที่สิ้นสุด
              $data_stage : กำหนด data_stage_id สำหรับกรณีที่ต้องการ
              $param_questionaire_type_id : questionaire_type_id ที่ต้องการจากหน้าจอ
              $crosstab_number : ระบุ crosstab ที่ต้องการหาข้อมูล (ดูได้จากรายงานต้นฉบับ E:\PMTL\WWWR_Report_ASM_Performance_Dashboard.xlsx)
              $table_header_detail : กำหนดตารางที่ต้องการ count ข้อมูล
            */
            // ข้อมูลสำหรับ crosstab 1
            $eu->valuePM = $this->CalculatePercent($eu->under_emp, "2", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 1, "questionaire_data_header");
            $eu->valueVSM = $this->CalculatePercent($eu->under_emp, "3", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 1,"questionaire_data_header");
            $eu->valuePMKnow = $this->CalculatePercent($eu->under_emp, "2", $eu->start_valid_date, $eu->end_valid_date, "AND qdh.data_stage_id = 4", $param_questionaire_type_id, 1, "questionaire_data_header");
            $eu->PercentPM = ($eu->valuePM == 0 || $eu->FSF == 0)? "N/A" : (($eu->valuePM*100)/$eu->FSF);
            $eu->PercentVSM = ($eu->valueVSM == 0 || $eu->VSM == 0)? "N/A" : (($eu->valueVSM*100)/$eu->VSM);
            $eu->PercentPMKnow = ($eu->valuePMKnow == 0 || $eu->FSF == 0)? "N/A" : (($eu->valuePMKnow*100)/$eu->FSF);

            // ข้อมูลสำหรับ crosstab 2
            $eu->valueTotalForm = $this->CalculatePercent($eu->under_emp, "1,2,3", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_header");
            $eu->valueTotalOutlet = $this->CalculatePercent($eu->under_emp, "1,2,3", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_detail");
            $eu->TotalAVGFormMGR = ($eu->valueTotalForm == 0 || $eu->MGR == 0)? "N/A" : ($eu->valueTotalForm/$eu->MGR);
            $eu->TotalAVGOutletMGR = ($eu->valueTotalOutlet == 0 || $eu->MGR == 0)? "N/A" : ($eu->valueTotalOutlet/$eu->MGR);
            $eu->TotalAVGOutletForm = ($eu->valueTotalOutlet == 0 || $eu->valueTotalForm == 0)? "N/A" : ($eu->valueTotalOutlet/$eu->valueTotalForm);

            // ข้อมูลสำหรับ crosstab 3
            $eu->valuePMForm = $this->CalculatePercent($eu->under_emp, "2", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_header");
            $eu->valuePMOutlet = $this->CalculatePercent($eu->under_emp, "2", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_detail");
            $eu->PMAVGFormMGR = ($eu->valuePMForm == 0 || $eu->MGR == 0)? "N/A" : ($eu->valuePMForm/$eu->MGR);
            $eu->PMAVGOutletMGR = ($eu->valuePMOutlet == 0 || $eu->MGR == 0)? "N/A" : ($eu->valuePMOutlet/$eu->MGR);
            $eu->PMAVGOutletForm = ($eu->valuePMOutlet == 0 || $eu->valuePMForm == 0)? "N/A" : ($eu->valuePMOutlet/$eu->valuePMForm);

            // ข้อมูลสำหรับ crosstab 4
            $eu->valueVSMForm = $this->CalculatePercent($eu->under_emp, "3", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_header");
            $eu->valueVSMOutlet = $this->CalculatePercent($eu->under_emp, "3", $eu->start_valid_date, $eu->end_valid_date, "", $param_questionaire_type_id, 2, "questionaire_data_detail");
            $eu->VSMAVGFormMGR = ($eu->valueVSMForm == 0 || $eu->MGR == 0)? "N/A" : ($eu->valueVSMForm/$eu->MGR);
            $eu->VSMAVGOutletMGR = ($eu->valueVSMOutlet == 0 || $eu->MGR == 0)? "N/A" : ($eu->valueVSMOutlet/$eu->MGR);
            $eu->VSMAVGOutletForm = ($eu->valueVSMOutlet == 0 || $eu->valueVSMForm == 0)? "N/A" : ($eu->valueVSMOutlet/$eu->valueVSMForm);

            // สร้าง variable ขึ้นมาเพื่อใช้รองรับสำหรับแต่ละ questionaire_type
            foreach ($questionaire_type as $qt) {
                $nameValueForm = "valueForm_Type".$qt->type_id;
                $nameValueOutlet = "valueOutlet_Type".$qt->type_id;
                $nameAVGFormMGR = "AVGFormMGR_Type".$qt->type_id;
                $nameAVGOutletMGR = "AVGOutletMGR_Type".$qt->type_id;
                $nameAVGOutletForm = "AVGOutletForm_Type".$qt->type_id;

                $eu->$nameValueForm = $this->CalculatePercent($eu->under_emp, "1,2,3", $eu->start_valid_date, $eu->end_valid_date, "", $qt->type_id, 2, "questionaire_data_header");;
                $eu->$nameValueOutlet = $this->CalculatePercent($eu->under_emp, "1,2,3", $eu->start_valid_date, $eu->end_valid_date, "", $qt->type_id, 2, "questionaire_data_detail");
                $eu->$nameAVGFormMGR = ($eu->$nameValueForm == 0 || $eu->MGR == 0)? "N/A" : ($eu->$nameValueForm/$eu->MGR);
                $eu->$nameAVGOutletMGR = ($eu->$nameValueOutlet == 0 || $eu->MGR == 0)? "N/A" : ($eu->$nameValueOutlet/$eu->MGR);
                $eu->$nameAVGOutletForm = ($eu->$nameValueOutlet == 0 || $eu->$nameValueForm == 0)? "N/A" : ($eu->$nameValueOutlet/$eu->$nameValueForm);
            }

            // ทำผลรวมของข้อมูลเพื่อเก็บไว้ใน $groups_emp_under สำหรับนำไปใช้แสดงในรายงาน
            $group_date = $eu->start_valid_date;

            if (!isset($groups_emp_under[$group_date])) {
              $groups_emp_under[$group_date]['sum_head_count_MGR'] = $eu->MGR;
              $groups_emp_under[$group_date]['sum_head_count_FSF'] = $eu->FSF;
              $groups_emp_under[$group_date]['sum_head_count_VSM'] = $eu->VSM;
            } else {
              $groups_emp_under[$group_date]['sum_head_count_MGR'] += $eu->MGR;
              $groups_emp_under[$group_date]['sum_head_count_FSF'] += $eu->FSF;
              $groups_emp_under[$group_date]['sum_head_count_VSM'] += $eu->VSM;
            }
          }
          // ออก log ของ laravel เพื่อใช้ตรวจสอบข้อมูล
          Log::info('data and under_emp');
          Log::info($emp_under);
          return response()->json($emp_under);
          //----------------------------------[End] Query ของชุดข้อมูลตามจริง ทำขึ้นเพื่อใช้ในการจัดการข้อมูล -----------------------------------------------

          //----------------------------------[Start] Query ของชุดข้อมูลเพื่อใช้แสดงรายงาน ซึ่งจะทำการจัดรูปแบบ -----------------------------------------------
          $position_headcount_date_condition = "
            SELECT h.position_id
            , po.position_name
            , h.valid_date as start_valid_date
            , COALESCE(
              (SELECT hc.valid_date-INTERVAL 1 DAY
              FROM head_count hc
              WHERE hc.valid_date > h.valid_date
              ORDER BY hc.valid_date ASC
              LIMIT 1)
              , CONCAT(2019,'-12-31')
            ) as end_valid_date
            , md.max_date
            , jf.job_function_group_id
            , (CASE WHEN jf.job_function_group_id = 1 THEN SUM(h.head_count) ELSE 0 END) as MGR
            , (CASE WHEN jf.job_function_group_id = 2 THEN SUM(h.head_count) ELSE 0 END) as FSF
            , (CASE WHEN jf.job_function_group_id = 3 THEN SUM(h.head_count) ELSE 0 END) as VSM
            , SUM(h.head_count) as head_count
            FROM head_count h
            INNER JOIN job_function jf ON h.job_function_id = jf.job_function_id
            INNER JOIN position po ON po.position_id = h.position_id
            CROSS JOIN (
              SELECT MAX(valid_date) as max_date
              FROM head_count
              WHERE YEAR(valid_date) = ".$param_year."
            ) as md
            WHERE YEAR(h.valid_date) = ".$param_year."
            AND h.valid_date = md.max_date
            GROUP BY h.position_id
            , po.position_name
            , h.valid_date
            , md.max_date
            , jf.job_function_group_id ";

          $group_position_headcount_date_condition = "
            SELECT field.position_id
            , field.position_name
            , field.start_valid_date
            , field.end_valid_date
            , field.max_date
            , (SELECT GROUP_CONCAT(em.emp_snapshot_id)
              FROM employee_snapshot em
              WHERE field.position_id = em.position_id
              AND em.start_date BETWEEN field.start_valid_date AND field.end_valid_date
            ) as emp_snapshot_id
            , MAX(field.MGR) MGR
            , MAX(field.FSF) FSF
            , MAX(field.VSM) VSM
            FROM (".$position_headcount_date_condition.") field
            GROUP BY field.position_id
            , field.position_name
            , field.start_valid_date
            , field.end_valid_date
            , field.max_date ";

        // period ทั้งหมดที่มีในระบบ
        $order_period = "
          SELECT valid_date
          FROM head_count
          WHERE YEAR(valid_date) = ".$param_year."
          AND DAY(valid_date) = 1
          GROUP BY valid_date";

        // เพิ่ม field บางส่วนขึ้นมาเพื่อใช้ในการกำหนดค่าข้อมูลที่ต้องการแสดง
        $head_count = DB::select("
          SELECT pos.*
          , per.valid_date
          , 4 as group_cycle_id
          , '' as group_cycle
          , 6 as group_total_id
          , '' as group_total
          , 6 as group_pm_id
          , '' as group_pm
          , 6 as group_vsm_id
          , '' as group_vsm
          , 0 as group_questionaire
          , 6 as group_questionaire_type_id
          , '' as group_questionaire_type
          , 0 as value
          , 0 as value_cycle
          , 0 as sum_total_value_period
          , 0 as sum_pm_value_period
          , 0 as sum_vsm_value_period
          , 0 as sum_type_value_period
          , DATE_FORMAT(per.valid_date, '%b-%y') as cycle_name
          FROM (".$group_position_headcount_date_condition.") pos
          CROSS JOIN (".$order_period.") per
          ORDER BY pos.position_id ASC, per.valid_date ASC ");
        //----------------------------------[End] Query ของชุดข้อมูลเพื่อใช้แสดงรายงาน ซึ่งจะทำการจัดรูปแบบ -----------------------------------------------

        //----------------------------------[Start] สร้าง Array เพื่อใช้สำหรับการจัดทำข้อมูลแสดงรายงาน -----------------------------------------------
        $head_count = collect($head_count);
        // สำหรับ crosstab 1
        $CoveragePM = collect();
        $CoverageVSM = collect();
        $AcknowledgePM = collect();
        // สำหรับ crosstab 2
        $AssessmentPerformed = collect();
        $OutletVisited = collect();
        $AVGTotalAssessment = collect();
        $AVGOutletVisited = collect();
        $AVGOutletAssessment = collect();
        // สำหรับ crosstab 3
        $PMAssessmentPerformed = collect();
        $PMOutletVisited = collect();
        $PMAVGTotalAssessment = collect();
        $PMAVGOutletVisited = collect();
        $PMAVGOutletAssessment = collect();
        // สำหรับ crosstab 4
        $VSMAssessmentPerformed = collect();
        $VSMOutletVisited = collect();
        $VSMAVGTotalAssessment = collect();
        $VSMAVGOutletVisited = collect();
        $VSMAVGOutletAssessment = collect();

        // สำหรับ crosstab group_questionaire_type
        $result_Type = [];
        foreach ($questionaire_type as $qt) {
          $nameArrayOne = "AssessmentPerformedType_".$qt->type_id;
          $nameArrayTwo = "OutletVisitedType_".$qt->type_id;
          $nameArrayThree = "AVGTotalAssessmentType_".$qt->type_id;
          $nameArrayFour = "AVGOutletVisitedType_".$qt->type_id;
          $nameArrayFive = "AVGOutletAssessmentType_".$qt->type_id;

          $nameArrayOne = collect();
          $nameArrayTwo = collect();
          $nameArrayThree = collect();
          $nameArrayFour = collect();
          $nameArrayFive = collect();

          foreach ($head_count as $key => $value) {
            $nameArrayOne->push(clone $value);
            $nameArrayTwo->push(clone $value);
            $nameArrayThree->push(clone $value);
            $nameArrayFour->push(clone $value);
            $nameArrayFive->push(clone $value);
          }

          foreach ($nameArrayOne as $key => $value) {
            $value->group_questionaire = $qt->type_id;
            $value->group_questionaire_type = "Number of ".$qt->type_name;
            $value->group_questionaire_type_id = 1;
          }
          foreach ($nameArrayTwo as $key => $value) {
            $value->group_questionaire = $qt->type_id;
            $value->group_questionaire_type = "Number outlet of ".$qt->type_name;
            $value->group_questionaire_type_id = 2;
          }
          foreach ($nameArrayThree as $key => $value) {
            $value->group_questionaire = $qt->type_id;
            $value->group_questionaire_type = "AVG. ".$qt->type_name." (Time)";
            $value->group_questionaire_type_id = 3;
          }
          foreach ($nameArrayFour as $key => $value) {
            $value->group_questionaire = $qt->type_id;
            $value->group_questionaire_type = "AVG. outlet visited";
            $value->group_questionaire_type_id = 4;
          }
          foreach ($nameArrayFive as $key => $value) {
            $value->group_questionaire = $qt->type_id;
            $value->group_questionaire_type = "AVG. outlet visited / assessment";
            $value->group_questionaire_type_id = 5;
          }

          $result_Type = array_merge($result_Type, $nameArrayOne->toArray(), $nameArrayTwo->toArray(), $nameArrayThree->toArray(), $nameArrayFour->toArray() ,$nameArrayFive->toArray());
        }
        $result_Type = collect($result_Type);
        // return response()->json($result_Type);
        //----------------------------------[End] สร้าง Array เพื่อใช้สำหรับการจัดทำข้อมูลแสดงรายงาน -----------------------------------------------

        //----------------------------------[Start] Clone ข้อมูลจากชุดข้อมูลสำหรับแสดงรายงานให้กับ Array ตัวอื่นที่ต้องการให้มีข้อมูลเหมือนกัน -----------------------------------
        foreach ($head_count as $key => $value) {
          // สำหรับ crosstab 1
          $CoveragePM->push(clone $value);
          $CoverageVSM->push(clone $value);
          $AcknowledgePM->push(clone $value);
          // สำหรับ crosstab 2
          $AssessmentPerformed->push(clone $value);
          $OutletVisited->push(clone $value);
          $AVGTotalAssessment->push(clone $value);
          $AVGOutletVisited->push(clone $value);
          $AVGOutletAssessment->push(clone $value);
          // สำหรับ crosstab 3
          $PMAssessmentPerformed->push(clone $value);
          $PMOutletVisited->push(clone $value);
          $PMAVGTotalAssessment->push(clone $value);
          $PMAVGOutletVisited->push(clone $value);
          $PMAVGOutletAssessment->push(clone $value);
          // สำหรับ crosstab 4
          $VSMAssessmentPerformed->push(clone $value);
          $VSMOutletVisited->push(clone $value);
          $VSMAVGTotalAssessment->push(clone $value);
          $VSMAVGOutletVisited->push(clone $value);
          $VSMAVGOutletAssessment->push(clone $value);
        }
        //----------------------------------[End] Clone ข้อมูลจากชุดข้อมูลสำหรับแสดงรายงานให้กับ Array ตัวอื่นที่ต้องการให้มีข้อมูลเหมือนกัน -----------------------------------

        //----------------------------------[Start] กำหนดชื่อและรหัสของ group ที่ต้องการแสดงในรายงาน -----------------------------------------
        // group_cycle, group_cycle_id สำหรับ crosstab 1
        foreach ($CoveragePM as $key => $value) {
          $value->group_cycle = "%Coverage PM";
          $value->group_cycle_id = 1;
        }

        foreach ($CoverageVSM as $key => $value) {
          $value->group_cycle = "%Coverage VSM";
          $value->group_cycle_id = 2;
        }

        foreach ($AcknowledgePM as $key => $value) {
          $value->group_cycle = "%Acknowledge PM";
          $value->group_cycle_id = 3;
        }

        // group_total, group_total_id สำหรับ crosstab 2
        foreach ($AssessmentPerformed as $as => $value) {
          $value->group_total = "Total Assessment Performed";
          $value->group_total_id = 1;
        }

        foreach ($OutletVisited as $as => $value) {
          $value->group_total = "Total outlet visited";
          $value->group_total_id = 2;
        }

        foreach ($AVGTotalAssessment as $as => $value) {
          $value->group_total = "AVG. Assessment per Manager";
          $value->group_total_id = 3;
        }

        foreach ($AVGOutletVisited as $as => $value) {
          $value->group_total = "AVG. outlet visited";
          $value->group_total_id = 4;
        }

        foreach ($AVGOutletAssessment as $as => $value) {
          $value->group_total = "AVG. outlet visited / assessment";
          $value->group_total_id = 5;
        }

        // group_pm, group_pm_id สำหรับ crosstab 3
        foreach ($PMAssessmentPerformed as $as => $value) {
          $value->group_pm = "Total Assessment Performed PM";
          $value->group_pm_id = 1;
        }

        foreach ($PMOutletVisited as $as => $value) {
          $value->group_pm = "Total outlet visited PM";
          $value->group_pm_id = 2;
        }

        foreach ($PMAVGTotalAssessment as $as => $value) {
          $value->group_pm = "AVG. Assessment per Manager PM";
          $value->group_pm_id = 3;
        }

        foreach ($PMAVGOutletVisited as $as => $value) {
          $value->group_pm = "AVG. outlet visited PM";
          $value->group_pm_id = 4;
        }

        foreach ($PMAVGOutletAssessment as $as => $value) {
          $value->group_pm = "AVG. outlet visited / assessment PM";
          $value->group_pm_id = 5;
        }

        // group_vsm, group_vsm_id สำหรับ crosstab 4
        foreach ($VSMAssessmentPerformed as $as => $value) {
          $value->group_vsm = "Total Assessment Performed VSM";
          $value->group_vsm_id = 1;
        }

        foreach ($VSMOutletVisited as $as => $value) {
          $value->group_vsm = "Total outlet visited VSM";
          $value->group_vsm_id = 2;
        }

        foreach ($VSMAVGTotalAssessment as $as => $value) {
          $value->group_vsm = "AVG. Assessment per Manager VSM";
          $value->group_vsm_id = 3;
        }

        foreach ($VSMAVGOutletVisited as $as => $value) {
          $value->group_vsm = "AVG. outlet visited VSM";
          $value->group_vsm_id = 4;
        }

        foreach ($VSMAVGOutletAssessment as $as => $value) {
          $value->group_vsm = "AVG. outlet visited / assessment VSM";
          $value->group_vsm_id = 5;
        }
        //----------------------------------[End] กำหนดชื่อและรหัสของ group ที่ต้องการแสดงในรายงาน -----------------------------------------

        //----------------------------------[Start] Merge ชุดข้อมูล Array เพื่อใช้แสดงรายงาน -----------------------------------------
		    $result = array_merge($CoveragePM->toArray(),$CoverageVSM->toArray(),$AcknowledgePM->toArray()
                  ,$AssessmentPerformed->toArray(),$OutletVisited->toArray(),$AVGTotalAssessment->toArray(),$AVGOutletVisited->toArray(),$AVGOutletAssessment->toArray()
                  ,$PMAssessmentPerformed->toArray(),$PMOutletVisited->toArray(),$PMAVGTotalAssessment->toArray(),$PMAVGOutletVisited->toArray(),$PMAVGOutletAssessment->toArray()
                  ,$VSMAssessmentPerformed->toArray(),$VSMOutletVisited->toArray(),$VSMAVGTotalAssessment->toArray(),$VSMAVGOutletVisited->toArray(),$VSMAVGOutletAssessment->toArray()
                  ,$result_Type->toArray()); //,$result_Type
        $result = collect($result);
        // return response()->json($result);
        //----------------------------------[End] Merge ชุดข้อมูล Array เพื่อใช้แสดงรายงาน -----------------------------------------

        // set ค่าของข้อมูลตามจริงที่คำนวน ใส่ในข้อมูลตามรูปแบบของรายงาน
        $groups = [];
        $groups_total = [];
        $groups_pm = [];
        $groups_vsm = [];
        $groups_type = [];
        foreach ($result as $re) {
          foreach ($emp_under as $eu) {
              //----------------------------------[Start] กำหนดข้อมูลจากชุดข้อมูลจริง สู่ชุดข้อมูลแสดงรายงาน -----------------------------------------------
              // $re->group_cycle_id กำหนดค่าของ crosstab 1
              if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_cycle_id == 1){
                $re->value = $eu->valuePM;
                $re->value_cycle = ($eu->PercentPM == "N/A") ? $eu->PercentPM : (number_format((float)$eu->PercentPM, 2, '.', ',')."%");
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_cycle_id == 2){
                $re->value = $eu->valueVSM;
                $re->value_cycle = ($eu->PercentVSM == "N/A") ? $eu->PercentVSM : (number_format((float)$eu->PercentVSM, 2, '.', ',')."%");
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_cycle_id == 3){
                $re->value = $eu->valuePMKnow;
                $re->value_cycle = ($eu->PercentPMKnow == "N/A") ? $eu->PercentPMKnow : (number_format((float)$eu->PercentPMKnow, 2, '.', ',')."%");
              }

              // $re->group_total_id กำหนดค่าของ crosstab 2
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_total_id == 1){
                $re->value = ($eu->valueTotalForm == 0)? "N/A" : number_format((float)$eu->valueTotalForm, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_total_id == 2){
                $re->value = ($eu->valueTotalOutlet == 0)? "N/A" : number_format((float)$eu->valueTotalOutlet, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_total_id == 3){
                $re->value = ($eu->TotalAVGFormMGR == "N/A") ? $eu->TotalAVGFormMGR : (number_format((float)$eu->TotalAVGFormMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_total_id == 4){
                $re->value = ($eu->TotalAVGOutletMGR == "N/A") ? $eu->TotalAVGOutletMGR : (number_format((float)$eu->TotalAVGOutletMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_total_id == 5){
                $re->value = ($eu->TotalAVGOutletForm == "N/A") ? $eu->TotalAVGOutletForm : (number_format((float)$eu->TotalAVGOutletForm, 2, '.', ','));
              }

              // $re->group_pm_id กำหนดค่าของ crosstab 3
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_pm_id == 1){
                $re->value = ($eu->valuePMForm == 0)? "N/A" : number_format((float)$eu->valuePMForm, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_pm_id == 2){
                $re->value = ($eu->valuePMOutlet == 0)? "N/A" : number_format((float)$eu->valuePMOutlet, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_pm_id == 3){
                $re->value = ($eu->PMAVGFormMGR == "N/A") ? $eu->PMAVGFormMGR : (number_format((float)$eu->PMAVGFormMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_pm_id == 4){
                $re->value = ($eu->PMAVGOutletMGR == "N/A") ? $eu->PMAVGOutletMGR : (number_format((float)$eu->PMAVGOutletMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_pm_id == 5){
                $re->value = ($eu->PMAVGOutletForm == "N/A") ? $eu->PMAVGOutletForm : (number_format((float)$eu->PMAVGOutletForm, 2, '.', ','));
              }

              // $re->group_vsm_id กำหนดค่าของ crosstab 4
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_vsm_id == 1){
                $re->value = ($eu->valueVSMForm == 0)? "N/A" : number_format((float)$eu->valueVSMForm, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_vsm_id == 2){
                $re->value = ($eu->valueVSMOutlet == 0)? "N/A" : number_format((float)$eu->valueVSMOutlet, 0, '', ',');
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_vsm_id == 3){
                $re->value = ($eu->VSMAVGFormMGR == "N/A") ? $eu->VSMAVGFormMGR : (number_format((float)$eu->VSMAVGFormMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_vsm_id == 4){
                $re->value = ($eu->VSMAVGOutletMGR == "N/A") ? $eu->VSMAVGOutletMGR : (number_format((float)$eu->VSMAVGOutletMGR, 2, '.', ','));
              }
              else if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_vsm_id == 5){
                $re->value = ($eu->VSMAVGOutletForm == "N/A") ? $eu->VSMAVGOutletForm : (number_format((float)$eu->VSMAVGOutletForm, 2, '.', ','));
              }

              // $re->group_questionaire_type_id กำหนดค่าของ crosstab type
              foreach ($questionaire_type as $qt) {
                $nameValueForm = "valueForm_Type".$qt->type_id;
                $nameValueOutlet = "valueOutlet_Type".$qt->type_id;
                $nameAVGFormMGR = "AVGFormMGR_Type".$qt->type_id;
                $nameAVGOutletMGR = "AVGOutletMGR_Type".$qt->type_id;
                $nameAVGOutletForm = "AVGOutletForm_Type".$qt->type_id;

                if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_questionaire == $qt->type_id && $re->group_questionaire_type_id == 1){
                  $re->value = ($eu->$nameValueForm == 0)? "N/A" : number_format((float)$eu->$nameValueForm, 0, '', ',');
                }
                if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_questionaire == $qt->type_id && $re->group_questionaire_type_id == 2){
                  $re->value = ($eu->$nameValueOutlet == 0)? "N/A" : number_format((float)$eu->$nameValueOutlet, 0, '', ',');
                }
                if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_questionaire == $qt->type_id && $re->group_questionaire_type_id == 3){
                  $re->value = ($eu->$nameAVGFormMGR == "N/A") ? $eu->$nameAVGFormMGR : (number_format((float)$eu->$nameAVGFormMGR, 2, '.', ','));
                }
                if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_questionaire == $qt->type_id && $re->group_questionaire_type_id == 4){
                  $re->value = ($eu->$nameAVGOutletMGR == "N/A") ? $eu->$nameAVGOutletMGR : (number_format((float)$eu->$nameAVGOutletMGR, 2, '.', ','));
                }
                if ($re->valid_date == $eu->start_valid_date && $re->position_id == $eu->position_id && $re->group_questionaire == $qt->type_id && $re->group_questionaire_type_id == 5){
                  $re->value = ($eu->$nameAVGOutletForm == "N/A") ? $eu->$nameAVGOutletForm : (number_format((float)$eu->$nameAVGOutletForm, 2, '.', ','));
                }
              }

              //----------------------------------[End] กำหนดข้อมูลจากชุดข้อมูลจริง สู่ชุดข้อมูลแสดงรายงาน -----------------------------------------------
          }// emp_under

          //----------------------------------[Start] ทำผลรวมจากชุดข้อมูลแสดงรายงาน ซึ่งรวมข้อมูลจาก group และ period -----------------------------------------------
          // group_cycle_id รวมข้อมูลสำหรับ crosstab 1
          $group_cycle = $re->group_cycle_id;
          $group_date = $re->valid_date;

          if (!isset($groups[$group_cycle][$group_date])) {
            $groups[$group_cycle][$group_date]['sum_value_cycle_period'] = $re->value;
          } else {
            $groups[$group_cycle][$group_date]['sum_value_cycle_period'] += $re->value;
          }

          // group_total_id รวมข้อมูลสำหรับ crosstab 2
          $group_total = $re->group_total_id;
          if (!isset($groups_total[$group_total][$group_date])) {
            $groups_total[$group_total][$group_date]['sum_value_total_period'] = $re->value;
          } else {
            $groups_total[$group_total][$group_date]['sum_value_total_period'] += $re->value;
          }

          // group_pm_id รวมข้อมูลสำหรับ crosstab 3
          $group_pm = $re->group_pm_id;
          if (!isset($groups_pm[$group_pm][$group_date])) {
            $groups_pm[$group_pm][$group_date]['sum_value_pm_period'] = $re->value;
          } else {
            $groups_pm[$group_pm][$group_date]['sum_value_pm_period'] += $re->value;
          }

          // group_vsm_id รวมข้อมูลสำหรับ crosstab 4
          $group_vsm = $re->group_vsm_id;
          if (!isset($groups_vsm[$group_vsm][$group_date])) {
            $groups_vsm[$group_vsm][$group_date]['sum_value_vsm_period'] = $re->value;
          } else {
            $groups_vsm[$group_vsm][$group_date]['sum_value_vsm_period'] += $re->value;
          }

          // group_vsm_id รวมข้อมูลสำหรับ crosstab type
          $group_questionaire = $re->group_questionaire;
          $group_type = $re->group_questionaire_type_id;
          if (!isset($groups_type[$group_questionaire][$group_type][$group_date])) {
            $groups_type[$group_questionaire][$group_type][$group_date]['sum_value_type_period'] = $re->value;
          } else {
            $groups_type[$group_questionaire][$group_type][$group_date]['sum_value_type_period'] += $re->value;
          }
          //----------------------------------[End] ทำผลรวมจากชุดข้อมูลแสดงรายงาน ซึ่งรวมข้อมูลจาก group และ period -----------------------------------------------

          //----------------------------------[Start] เก็บข้อมูลผลรวมของชุดข้อมูลจริงไว้ในชุดข้อมูลแสดงรายงาน -----------------------------------------------
          $re->sum_head_count_MGR = $groups_emp_under[$re->max_date]['sum_head_count_MGR'];
          $re->sum_head_count_FSF = $groups_emp_under[$re->max_date]['sum_head_count_FSF'];
          $re->sum_head_count_VSM = $groups_emp_under[$re->max_date]['sum_head_count_VSM'];
          $re->sum_head_count_period_MGR = $groups_emp_under[$re->valid_date]['sum_head_count_MGR'];
          $re->sum_head_count_period_FSF = $groups_emp_under[$re->valid_date]['sum_head_count_FSF'];
          $re->sum_head_count_period_VSM = $groups_emp_under[$re->valid_date]['sum_head_count_VSM'];
          //----------------------------------[End] เก็บข้อมูลผลรวมของชุดข้อมูลจริงไว้ในชุดข้อมูลแสดงรายงาน -----------------------------------------------

        }// result

        //----------------------------------[Start] คำนวนข้อมูล % จากชุดข้อมูลแสดงรายงาน และเก็บค่าผลรวม-----------------------------------------------
        foreach ($result as $re) {
          // คำนวนข้อมูล % [เขียนแยกออกมาเพราะต้องการใช้งานข้อมูลรวม ดังนั้นจึงทำในขั้นตอนทำผลรวมของข้อมูลไม่ได้] สำหรับ crosstab 1
          if ($re->group_cycle_id == 1 || $re->group_cycle_id == 3){
            $name_sum = "sum_head_count_FSF";
          }else if ($re->group_cycle_id == 2){
            $name_sum = "sum_head_count_VSM";
          }
          $re->sum_cycle_date = $groups[$re->group_cycle_id][$re->valid_date]['sum_value_cycle_period'];
          $re->percent_cycle_date_value = ($groups[$re->group_cycle_id][$re->valid_date]['sum_value_cycle_period'] == 0 || $groups_emp_under[$re->valid_date][$name_sum] == 0)?
                                          "N/A" : (($groups[$re->group_cycle_id][$re->valid_date]['sum_value_cycle_period']*100)/$groups_emp_under[$re->valid_date][$name_sum]);
          $re->percent_cycle_date = ($re->percent_cycle_date_value == "N/A") ? $re->percent_cycle_date_value : (number_format((float)$re->percent_cycle_date_value, 2, '.', ',')."%");

          // เก็บค่าผลรวมสำหรับ crosstab 2
          $re->sum_total_value_period = number_format((float)$groups_total[$re->group_total_id][$re->valid_date]['sum_value_total_period'], 0, '', ',');

          // เก็บค่าผลรวมสำหรับ crosstab 3
          $re->sum_pm_value_period = number_format((float)$groups_pm[$re->group_pm_id][$re->valid_date]['sum_value_pm_period'], 0, '', ',');

          // เก็บค่าผลรวมสำหรับ crosstab 4
          $re->sum_vsm_value_period = number_format((float)$groups_vsm[$re->group_vsm_id][$re->valid_date]['sum_value_vsm_period'], 0, '', ',');

          // เก็บค่าผลรวมสำหรับ crosstab type
          $re->sum_type_value_period = number_format((float)$groups_type[$re->group_questionaire][$re->group_questionaire_type_id][$re->valid_date]['sum_value_type_period'], 0, '', ',');
        }
        //----------------------------------[End] คำนวนข้อมูล % จากชุดข้อมูลแสดงรายงาน  -----------------------------------------------

		    return response()->json($result);

        // return response()->json(compact('head_count', 'new'), 200);

    }

    public function CalculatePercent($under_emp, $job_group, $start_valid_date, $end_valid_date, $data_stage
                                    , $param_questionaire_type_id, $crosstab_number, $table_header_detail)
    {
      // $under_emp, $job_group, $start_valid_date, $end_valid_date, $data_stage, $param_questionaire_type_id, $crosstab_number, $table_header_detail
        // $under_emp = '5,35,36,45,49,68,80,89,90,111,112,115,147,155,159,165,167,178,185,190,200,224,225,281,291,294,298,301,305,312,313,334,337,11,12,13,14,15,16,17,18,19,20,22,23,24,25,26,27,39,53,62,131,290,309';
        // $job_group = '1,2,3';
        // $start_valid_date = '2019-01-01';
        // $end_valid_date = '2019-03-31';
        // $data_stage = '';
        // $param_questionaire_type_id = 1;
        // $crosstab_number = 2;
        // $table_header_detail = 'questionaire_data_header';


        // ข้อมูลที่รับเข้ามาภายใน function
        /*
          $under_emp : ลูกน้องภายในทั้งหมดภายในสาย
          $job_group : จำนวน job_function_group_id ของ job_function ว่า
          $start_valid_date : วันที่เริ่มต้น
          $end_valid_date : วันที่สิ้นสุด
          $data_stage : กำหนด data_stage_id สำหรับกรณีที่ต้องการ
          $param_questionaire_type_id : questionaire_type_id ที่ต้องการจากหน้าจอ
          $crosstab_number : ระบุ crosstab ที่ต้องการหาข้อมูล (ดูได้จากรายงานต้นฉบับ E:\PMTL\WWWR_Report_ASM_Performance_Dashboard.xlsx)
          $table_header_detail : กำหนดตารางที่ต้องการ count ข้อมูล
        */

        // การคำนวนข้อมูลต่างกัน จึงจำเป็นต้องแยก
        //--------------------------------------- [Start] นับจำนวนคนเหมือนรูปแบบ Report FSF_HC สำหรับ Crosstab 1 เท่านั้น !! ----------------------------------------
        if ($crosstab_number == 1){
          // แยก employee ตาม level เพื่อนำไปหาต่อใน questionaire_data_header
          $emp_job_group = DB::select("
            SELECT GROUP_CONCAT(em.emp_snapshot_id) as emp_snapshot_id
            , em.level_id
            , jf.job_function_group_id
            , (CASE WHEN em.level_id != 2 THEN 'assessor_id' ELSE 'emp_snapshot_id' END) as emp_questionaire
            FROM employee_snapshot em
            INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
            WHERE FIND_IN_SET(em.emp_snapshot_id, '".$under_emp."')
            AND FIND_IN_SET(jf.job_function_group_id, '".$job_group."')
            GROUP BY em.level_id
            , jf.job_function_group_id");

          $countEmp = 0;
          foreach ($emp_job_group as $ejg) {
            // นับจำนวน employee ที่มีใน tabel transection
            $valueQuestion = DB::select("
              SELECT COUNT(DISTINCT qdh.".$ejg->emp_questionaire.") as num_form
              FROM questionaire_data_header qdh
              INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
              INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
              WHERE FIND_IN_SET(qdh.".$ejg->emp_questionaire.",'".$ejg->emp_snapshot_id."')
              ".$data_stage."
              AND FIND_IN_SET(qt.questionaire_type_id,'".$param_questionaire_type_id."')
              AND qdh.questionaire_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."'");

            if(!empty($valueQuestion)){
              $countEmp += $valueQuestion[0]->num_form;
            }
          }

          return $countEmp;
        //--------------------------------------- [End] นับจำนวนคนเหมือนรูปแบบ Report FSF_HC สำหรับ Crosstab 1 เท่านั้น !! ----------------------------------------

        //--------------------------------------- [Start] นับจำนวนคนเหมือนรูปแบบใหม่ ใช้สำหรับทุก crosstab ยกเว้นแค่ตัวแรก ----------------------------------------
        }else if ($crosstab_number == 2){
          // หาคนที่ถูกประเมิน โดยเอาเฉพาะ job_function 2,3
          $emp_snapshot_id = DB::select("
            SELECT GROUP_CONCAT(em.emp_snapshot_id) as emp_snapshot_id
            FROM employee_snapshot em
            INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
            WHERE FIND_IN_SET(em.emp_snapshot_id, '".$under_emp."')
            AND FIND_IN_SET(jf.job_function_group_id, '2,3')");

          // หาคนประเมิน โดยเอาเฉพาะ job_function 1
          $assessor_id = DB::select("
            SELECT GROUP_CONCAT(em.emp_snapshot_id) as assessor_id
            FROM employee_snapshot em
            INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
            WHERE FIND_IN_SET(em.emp_snapshot_id, '".$under_emp."')
            AND FIND_IN_SET(jf.job_function_group_id, '1')");

          if (empty($emp_snapshot_id) || empty($assessor_id)){
            return 0;
          }else {
            if ($table_header_detail == "questionaire_data_header"){
              // นับจำนวน form
              $valueQuestion = DB::select("
                SELECT COUNT(DISTINCT qdh.data_header_id) as num_form
                FROM questionaire_data_header qdh
                INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
                INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
                WHERE FIND_IN_SET(qdh.emp_snapshot_id,'".$emp_snapshot_id[0]->emp_snapshot_id."')
                AND FIND_IN_SET(qdh.assessor_id,'".$assessor_id[0]->assessor_id."')
                AND FIND_IN_SET(qt.questionaire_type_id,'".$param_questionaire_type_id."')
                AND qdh.questionaire_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."'");

            }else if ($table_header_detail == "questionaire_data_detail"){
              //  นับจำนวนผู้ที่ไปเยี่ยมร้าน
              $valueQuestion = DB::select("
                SELECT COUNT(DISTINCT qdd.customer_id) as num_form
                FROM questionaire_data_detail qdd
                INNER JOIN questionaire_data_header qdh ON qdh.data_header_id = qdd.data_header_id
                INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
                INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
                WHERE FIND_IN_SET(qdh.emp_snapshot_id,'".$emp_snapshot_id[0]->emp_snapshot_id."')
                AND FIND_IN_SET(qdh.assessor_id,'".$assessor_id[0]->assessor_id."')
                AND FIND_IN_SET(qt.questionaire_type_id,'".$param_questionaire_type_id."')
                AND qdh.questionaire_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."'");

            }

            $countEmp = 0;
            if(!empty($valueQuestion)){
              $countEmp = $valueQuestion[0]->num_form;
            }

            return $countEmp;
          }

        }
        //--------------------------------------- [End] นับจำนวนคนเหมือนรูปแบบใหม่ ใช้สำหรับทุก crosstab ยกเว้นแค่ตัวแรก ----------------------------------------

        /* // อดีต back up
        $emp_job_group = DB::select("
          SELECT GROUP_CONCAT(em.emp_snapshot_id) as emp_snapshot_id
          , em.level_id
          , jf.job_function_group_id
          , (CASE WHEN em.level_id != 2 THEN 'assessor_id' ELSE 'emp_snapshot_id' END) as emp_questionaire
          FROM employee_snapshot em
          INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
          WHERE FIND_IN_SET(em.emp_snapshot_id, '".$under_emp."')
          AND FIND_IN_SET(jf.job_function_group_id, '".$job_group."')
          GROUP BY em.level_id
          , jf.job_function_group_id");

        $countEmp = 0;
        foreach ($emp_job_group as $ejg) {
            // แยกให้มีการนับจำนวนข้อมูลตาม crosstab แต่ละตัว
            if ($crosstab_number == 1){
                $count_field = $ejg->emp_questionaire;  // นับข้อมูลตาม employee
            }else if ($crosstab_number == 2 && $table_header_detail == "questionaire_data_header"){
                $count_field = "data_header_id";    //นับจำนวน from
            }else if ($crosstab_number == 2 && $table_header_detail == "questionaire_data_detail"){
                $count_field = "customer_id";       // นับจำนวน ร้านค้า
            }

            if ($table_header_detail == "questionaire_data_header"){
              // นับจำนวนข้อมูลจาก questionaire_data_header
              $valueQuestion = DB::select("
                SELECT COUNT(DISTINCT qdh.".$count_field.") as num_form
                FROM questionaire_data_header qdh
                INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
                INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
                WHERE FIND_IN_SET(qdh.".$ejg->emp_questionaire.",'".$ejg->emp_snapshot_id."')
                ".$data_stage."
                AND FIND_IN_SET(qt.questionaire_type_id,'".$param_questionaire_type_id."')
                AND qdh.questionaire_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."'");

            }else if ($table_header_detail == "questionaire_data_detail"){
              // นับจำนวนข้อมูลจาก questionaire_data_detail
              $valueQuestion = DB::select("
                SELECT COUNT(DISTINCT qdd.".$count_field.") as num_form
                FROM questionaire_data_detail qdd
                INNER JOIN questionaire_data_header qdh ON qdh.data_header_id = qdd.data_header_id
                INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
                INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
                WHERE FIND_IN_SET(qdh.".$ejg->emp_questionaire.",'".$ejg->emp_snapshot_id."')
                ".$data_stage."
                AND FIND_IN_SET(qt.questionaire_type_id,'".$param_questionaire_type_id."')
                AND qdh.questionaire_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."'");

            }

            if(!empty($valueQuestion)){
              $countEmp += $valueQuestion[0]->num_form;
            }

        }

        return $countEmp;
        */
    }

    // หาลูกน้องทั้งหมด
    public function GetAllEmpCodeUnder($emp_snapshot_id, $start_valid_date, $end_valid_date)
    {
        //Request $request
        // $emp_snapshot_id, $start_valid_date, $end_valid_date
         // $emp_snapshot_id = $request->emp;
         // $start_valid_date = $request->start;
         // $end_valid_date = $request->end;

        $emp_code = DB::select("
          SELECT GROUP_CONCAT(emp_code) as emp_code
          FROM employee_snapshot
          WHERE FIND_IN_SET(emp_snapshot_id,'".$emp_snapshot_id."')");

        $parent = "";
        $parent_id = "";
        $place_parent = "";
        $place_parent_id = "";
        $have = true;

        // ตรวจสอบว่าหัวหน้ามีข้อมูลรึเปล่า
        if(empty($emp_code)) {
          $have = false;  // กรณีที่ไม่มีหัวหน้า
        }else { // หากมีข้อมูลให้เก็บไว้ใน parent เพื่อหาลูกน้องต่อไป
          foreach ($emp_code as $o) {
            $parent = $o->emp_code.",";
          }
        }

		    $num = 0;

        while($have){

          $employee = DB::select("
            SELECT GROUP_CONCAT(emp_code) as emp_code
            , GROUP_CONCAT(emp_snapshot_id) as emp_snapshot_id
            FROM employee_snapshot
            WHERE FIND_IN_SET(chief_emp_code,'".$parent."')
            AND chief_emp_code != ''
            AND start_date BETWEEN '".$start_valid_date."' AND '".$end_valid_date."' ");

          foreach ($employee as $emp) {
             if ($emp->emp_code == null && $emp->emp_snapshot_id == null){
               $have = false;
             }
             else if ($emp->emp_code != null && $emp->emp_snapshot_id != null){
               $place_parent = $place_parent.$parent; // เก็บ emp_code ก่อนหน้าไว้ใน $place_parent
               $place_parent_id = $place_parent_id.$parent_id; // เก็บ emp_snapshot_id ก่อนหน้าไว้ใน $place_parent_id
               $parent = "";
               $parent_id = "";

               $parent = $parent.$emp->emp_code.",";
               $parent_id = $parent_id.$emp->emp_snapshot_id.",";
             }
          }

    		   $num = $num+1; // จำนวนครั้งในการวน loop
      }// end while

      $place_parent = $place_parent.$parent; // เก็บ emp_code ล่าสุดที่หา emp_code ต่อไปไม่เจอ
      $place_parent_id = $place_parent_id.$parent_id; // เก็บ emp_code ล่าสุดที่หา emp_code ต่อไปไม่เจอ

      $active_report = DB::select("
        SELECT GROUP_CONCAT(em.emp_snapshot_id) as emp_snapshot_id
        FROM employee_snapshot em
        INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
        WHERE FIND_IN_SET(em.emp_snapshot_id,'".$place_parent_id."')
        AND em.emp_snapshot_id != ''
        AND jf.is_show_report = 1 ");

      $Allemp = "";     // เก็บ emp_snapshot_id ในรูปแบบของ String
      foreach ($active_report as $empID) {
        $Allemp = $Allemp.$empID->emp_snapshot_id;
      }

      return ($Allemp);

      // ขั้นตอนหาค่า emp_snapshot_id ที่อยู่ภายใต้ emp_snapshot_id ที่ต้องการ (emp_snapshot_id ที่เป็นลูกหลานทั้งหมด)
      // 1.หาชื่อ emp_code จาก emp_snapshot_id
      // 2.เก็บ emp_code ที่หาได้ (แม่) ไว้ใน parent
      //   •	วนหา emp_code ที่มี chief_emp_code เป็น parent
      //   •	หากไม่มีลูกของ parent จึงกำหนดให้หยุดการวน loop
      //   •	หากมีลูกของ parent ให้นำ parent เก็บไว้ใน place_parent
      //   •	แล้วกำหนดให้ parent เป็นค่าว่าง (เพื่อให้สามารถเก็บข้อมูลลูกที่พึ่งหาได้)
      //   •	กำหนด parent เป็น emp_code ของลูกที่พึ่งหาได้
      //   •	แล้วให้ทำการวน loop แบบนี้ไปเรื่อยๆจนกว่าจะหาลูกต่อไปไม่เจอ แล้วจึงให้หยุดการวน loop
      // 3.เก็บ emp_code ที่ใช้ในการหาลูกก่อนจะทำให้หยุดการวน loop ไว้ใน place_parent
      // 4.นำ place_parent หา emp_snapshot_id โดยไม่เอาตัวแม่ และเลือกเอาเฉพาะที่เป็น is_show_report = 1

    }

}
