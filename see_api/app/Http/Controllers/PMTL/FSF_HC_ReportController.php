<?php

namespace App\Http\Controllers\PMTL;

use App\Http\Controllers\PMTL\QuestionaireDataController;

use App\Customer;
use App\CustomerPosition;

use Auth;
use DB;
use DateTime;
use File;
use Validator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class FSF_HC_ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
    }

    public function index(Request $request)
    {
        $date_start = $request->date_start; // "01/01/2019"
        $param_date_start = date_format(date_create_from_format('d/m/Y', $date_start), 'Y-m-d'); // "2019-01-01"

        $date_end = $request->date_end; // "31/01/2019"
        $param_date_end = date_format(date_create_from_format('d/m/Y', $date_end), 'Y-m-d'); // "2019-01-31"

        $param_questionaire_type_id = json_decode($request->questionaire_type_id); // "1,2";

        // หา valid_date ที่มากที่สุดตาม parameter date
        $MaxDateHeadCount = "
            SELECT h.position_id
          	, MAX(h.valid_date) AS max_valid_date
          	FROM head_count h
          	WHERE (h.valid_date <= '".$param_date_start."' OR h.valid_date <= '".$param_date_end."')
          	GROUP BY h.position_id ";

        // หา emp_snapshot_id ตาม emp_code, position_id, MAX(start_date) [ใช้ใน query : $emp_position **ทำงานด้วยตัวมันเองไม่ได้]
        $emp_snapshot_id = "
            SELECT e.emp_snapshot_id
            FROM employee_snapshot e
            WHERE e.emp_code = em.emp_code
            AND e.position_id = em.position_id
            AND e.start_date = MAX(em.start_date)
            LIMIT 1";

        /*
		แสดง employee ที่มี MAX(start_date) โดย
        - MAX(start_date) น้อยกว่าหรือเท่ากับ MAX(valid_date) ตาม position_id
        - MAX(start_date) จะต้องตาม parameter date
        */
        $emp_position = "
            SELECT (".$emp_snapshot_id.") AS emp_snapshot_id
            , em.emp_code
            , CONCAT(em.emp_first_name,' ',em.emp_last_name) AS emp_name
            , em.position_id
            , po.position_code
            , MAX(em.start_date) AS max_date
            FROM employee_snapshot em
            LEFT JOIN position po ON em.position_id = po.position_id
            INNER JOIN (".$MaxDateHeadCount.") hc ON hc.position_id = em.position_id
            	AND em.start_date <= hc.max_valid_date
            WHERE em.level_id = 4
            AND (em.start_date <= '".$param_date_start."' OR em.start_date <= '".$param_date_end."')
            GROUP BY em.emp_code
            , CONCAT(em.emp_first_name,' ',em.emp_last_name)
            , em.position_id
            , po.position_code ";


         // job_function and type (all)
         $job_type = "
            SELECT j.job_function_id
            , j.job_function_name
            , t.questionaire_type_id
            , t.questionaire_type
            FROM job_function j
            CROSS JOIN questionaire_type t
            WHERE j.is_show_report = 1
            AND FIND_IN_SET(t.questionaire_type_id,'".$param_questionaire_type_id."')";

        /*
        get head_count (value) by position, job_function
		ข้อมูล head_count มานำแสดง จะเลือกตามวันที่ที่มากที่สุดของ valid_date ตาม parameter (เพื่อป้องกันการแสดงข้อมูลในอดีต)
        */
        $job_head_count = "
          SELECT emp.emp_snapshot_id
          , emp.emp_name
          , emp.position_id
          , emp.position_code
          , job.job_function_id
          , job.job_function_name
          , job.questionaire_type_id
          , job.questionaire_type
          , (SELECT h.head_count
            FROM head_count h
            WHERE h.valid_date = emp.max_date
            AND h.job_function_id = job.job_function_id
            AND h.position_id = emp.position_id LIMIT 1) as head_count
          FROM (".$emp_position.") emp
          CROSS JOIN (".$job_type.") job
          WHERE (emp.max_date <= '".$param_date_start."' OR emp.max_date <= '".$param_date_end."')";
          // LEFT JOIN head_count hc ON hc.job_function_id = job.job_function_id AND hc.position_id = emp.position_id AND hc.valid_date = emp.max_date


        // Information ชุดที่ 1
        $totalHeadCount = DB::select("
            SELECT
                main.position_code,
                main.position_id,
                main.emp_name,
                main.job_function_name,
                main.job_function_id,
                main.questionaire_type_id,
                main.questionaire_type,
                COALESCE(main.head_count,'N/A') AS head_count_str,
                main.head_count AS head_count_int,
                'Total Head Count' AS group_colume,
                0 AS group_colume_id,
                '".$date_start."' as start_date,
                '".$date_end."' as end_date,
                main.emp_snapshot_id as emp_snapshot_id,
                0 as under_emp,
                0 as sum_percent_coverage
            FROM (
              ".$job_head_count."
            ) main
            ORDER BY main.questionaire_type_id ,main.job_function_id
        ");

        // Information ชุดที่ 2
        $actualExecution = DB::select("
            SELECT
                main.position_code,
                main.position_id,
                main.emp_name,
                main.job_function_name,
                main.job_function_id,
                main.questionaire_type_id,
                main.questionaire_type,
                COALESCE(null,'N/A') AS head_count_str,
                COALESCE(null,0) AS head_count_int,
                'Actual Execution' AS group_colume,
                1 AS group_colume_id,
                '".$date_start."' as start_date,
                '".$date_end."' as end_date,
                main.emp_snapshot_id as emp_snapshot_id,
                0 as under_emp,
                0 as sum_percent_coverage
            FROM (
                ".$job_head_count."
            ) main
            ORDER BY main.questionaire_type_id ,main.job_function_id
            "
        );

        // Manage Information ชุดที่ 2
        foreach ($actualExecution as $exe) {
            // หาลูกน้องภายใต้ตัวเองทั้งหมด
            $exe->under_emp = $this->GetAllEmpCodeUnder($exe->emp_snapshot_id, $param_date_start, $param_date_end);

            // หา (assessor_id || emp_snapshot_id) ตาม level และใช้ emp ตาม job_function by record [level_id = 2 :: FF]
            $level_under_emp = DB::select("
                SELECT emp_snapshot_id
                , level_id
                , (CASE WHEN level_id != 2 THEN 'assessor_id' ELSE 'emp_snapshot_id' END) as emp_questionaire
                FROM employee_snapshot
                WHERE FIND_IN_SET(emp_snapshot_id, '".$exe->under_emp."')
                AND job_function_id = ".$exe->job_function_id." ");

            $count_int = 0;
            foreach ($level_under_emp as $under) {
               // ลูกน้องแต่ละคนมี questionaire_type และ parameter_date ตาม record
               $under_type = DB::select("
                  SELECT qt.questionaire_type
                  FROM questionaire_data_header qdh
                  INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
                  INNER JOIN questionaire_type qt ON q.questionaire_type_id = qt.questionaire_type_id
                  WHERE qdh.".$under->emp_questionaire." = ".$under->emp_snapshot_id."
                  AND qdh.questionaire_date BETWEEN '".$param_date_start."' AND '".$param_date_end."'
                  AND qt.questionaire_type_id = ".$exe->questionaire_type_id."
                  LIMIT 1 ");

               // หากมีให้นับเป็น 1 หากไม่มีก็ไม่นับ
               if(!empty($under_type)){
                  $count_int += 1;
               }
            }

            $exe->head_count_int = ($count_int == 0)? null : $count_int;
            $exe->head_count_str = ($count_int == 0)? "N/A" : $count_int;

       }


       // Information ชุดที่ 3
       $percentCoverage = DB::select("
           SELECT
               main.position_code,
               main.position_id,
               main.emp_name,
               main.job_function_name,
               main.job_function_id,
               main.questionaire_type_id,
               main.questionaire_type,
               COALESCE(main.head_count,'N/A') AS head_count_str,
               main.head_count AS head_count_int,
               '% Coverage' AS group_colume,
               2 AS group_colume_id,
               '".$date_start."' as start_date,
               '".$date_end."' as end_date,
               main.emp_snapshot_id as emp_snapshot_id,
               0 as under_emp,
               0 as sum_percent_coverage
           FROM (
             ".$job_head_count."
           ) main
           ORDER BY main.questionaire_type_id ,main.job_function_id
       ");


       // Information 1 + Information 2
       $result = array_merge($totalHeadCount,$actualExecution);

       // create group for sum Information 1,2  [for calculate % by Information 3]
       $groups = [];
       foreach ($result as $re) {
          $key_group = $re->group_colume;
          $key_type = $re->questionaire_type;
          $key_job = $re->job_function_name;

          // array(sum_count_int) = sum total , array(check_num) = ตรวจสอบข้อมูล N/A
          if (!isset($groups[$key_group][$key_type][$key_job])) {
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] = $re->head_count_int;
            $groups[$key_group][$key_type][$key_job]['check_num'] = (($re->head_count_str == "N/A")? 0 : 1);
          } else {
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] += $re->head_count_int;
            $groups[$key_group][$key_type][$key_job]['check_num'] += (($re->head_count_str == "N/A")? 0 : 1);
          }
      }

      // result sum data to (Information 1,2) และเช็คข้อมูลที่เป็น N/A
      foreach ($result as $re) {
        // ปรับตัวเลขในรูปแบบ #,##0
        $re->head_count_str = ($re->head_count_str != "N/A") ? number_format((float)$re->head_count_str, 0, '', ',') : $re->head_count_str;

        $sum_total = $groups[$re->group_colume][$re->questionaire_type][$re->job_function_name]['sum_count_int'];
        $type_total = $groups[$re->group_colume][$re->questionaire_type][$re->job_function_name]['check_num'];

        // ปรับตัวเลขในรูปแบบ #,##0
        $re->sum_percent_coverage = ($type_total == 0)? "N/A" : number_format((float)$sum_total, 0, '', ',');
      }


       // Manage Information ชุดที่ 3
       foreach ($percentCoverage as $pc) {
          // คำนวน % by record เฉพาะค่าที่ไม่เท่ากับ 0 (เพราะถ้าหากเป็นค่า 0 จะหาค่าไม่ได้)
          if($pc->head_count_int != 0 && $pc->head_count_str != "N/A"){
            foreach ($actualExecution as $ae) {
                if($pc->position_code == $ae->position_code && $pc->position_id == $ae->position_id
                  && $pc->emp_snapshot_id == $ae->emp_snapshot_id && $pc->job_function_id == $ae->job_function_id
                  && $pc->questionaire_type_id == $ae->questionaire_type_id){

                    // คำนวน % เฉพาะค่าที่ไม่เท่ากับ 0 (เพราะถ้าหากเป็นค่า 0 ผลการหา % ก็จะได้ 0)
                    if($ae->head_count_int != 0 && $ae->head_count_str != "N/A"){
                        // calculate % (Information 1 / Information 2) [number to float 2 degree, #,##0.00]
                        $percent = ($ae->head_count_int*100)/$pc->head_count_int;
                        $percent = number_format((float)$percent, 2, '.', ',');

                        $pc->head_count_int = $percent;
                        $pc->head_count_str = $percent."%";
                    }else if ($ae->head_count_int == 0 && $ae->head_count_str != "N/A"){
                        $pc->head_count_int = 0.00;
                        $pc->head_count_str = "0.00%";
                    }else if ($ae->head_count_str == "N/A"){
                        $pc->head_count_int = null;
                        $pc->head_count_str = "N/A";
                    }

                }

            }
          } else if ($pc->head_count_int == 0 || $pc->head_count_str == "N/A") { // กรณีที่ข้อมูลเป็น 0 หรือ null ให้แทนค่าได้เลย เนื่องจากคำนวน % ไม่ได้
            $pc->head_count_int = null;
            $pc->head_count_str = "N/A";
          }
          // end if คำนวน % by record


          // คำนวน % ของ total (โดยใช้ข้อมูลจาก total Information 1,2)
          $sum_info_head = $groups['Total Head Count'][$pc->questionaire_type][$pc->job_function_name]['sum_count_int'];  // Information 1
          $sum_info_actual = $groups['Actual Execution'][$pc->questionaire_type][$pc->job_function_name]['sum_count_int'];  // Information 2

          $type_info_head = $groups['Total Head Count'][$pc->questionaire_type][$pc->job_function_name]['check_num']; // type N/A Information 1
          $type_info_actual = $groups['Actual Execution'][$pc->questionaire_type][$pc->job_function_name]['check_num']; // type N/A Information 2

          if($sum_info_head != 0 && $type_info_head != 0
            && $sum_info_actual != 0 && $type_info_actual != 0){

            $percent_info = ($sum_info_actual*100)/$sum_info_head;
            $percent_info = number_format((float)$percent_info, 2, '.', ',');

            $pc->sum_percent_coverage = $percent_info."%";
          }else if ($sum_info_head == 0 || $type_info_head == 0 || $type_info_actual == 0){
            $pc->sum_percent_coverage = "N/A";
          }else if ($sum_info_actual == 0){
            $pc->sum_percent_coverage = "0.00%";
          }
          // end คำนวน % ของ total

       }


       // Information 1 + Information 2 + Information 3  [Information 1 + Information 2 = $result]
       $result = array_merge($result,$percentCoverage);

       $result = collect($result);
       $result = $result->sortBy('questionaire_type_id')->values()->all();

       //return response()->json($result);


        // ส่วนท้าย ทำการนำข้อมูลใส่ไฟล์ Json และส่งชื่อไฟล์ที่สร้างกลับไปยัง Front
        $now = new DateTime();
        $date = $now->format('Y-m-d_H-i-s');
        $data = json_encode($result);                                           // ใช้ $result เป็นข้อมูลภายในไฟล์ .JSON
        $namefile = 'FSF_HC_ReportController_' . $date;                         // NAME + DATE
        $fileName = $namefile . '.json';                                        // SAVE FILE JSON
        File::put(base_path("resources/generate/") . $fileName, $data);         // สร้างไฟล์ JSON บันทึกข้อมูลลง JSON ไฟล์
        if (file_exists(base_path("resources/generate/" . $fileName))) {        // ตรวจสอบว่าไฟล์ .JSON ได้มีการสร้างขึ้นรึยัง?
            return response()->json(['status' => 200, 'data' => $namefile]);    // ส่งชื่อไฟล์ .JSON ไปยัง Front
        } else {
            return response()->json(['status' => 400, 'data' => 'Generate File Not Success!']);
        }


      }

      public function GetAllEmpCodeUnder($emp_snapshot_id, $start_date, $end_date)
      {
        $emp_code = DB::select("SELECT emp_code
            FROM employee_snapshot
            WHERE emp_snapshot_id = ".$emp_snapshot_id."");

        $parent = "";
        $place_parent = "";
        $have = true;

        foreach ($emp_code as $o) {
          $parent = $o->emp_code.",";
        }

        // ใส่ AND chief_emp_code != 'anuaeid' เพื่อไม่ให้ run ข้อมูลนานเกินไป เนื่องจากตัวเองเป็นหัวหน้าของตัวเอง
        while($have){
          $emp = DB::select("SELECT emp_code
            FROM employee_snapshot
            WHERE FIND_IN_SET(chief_emp_code,'".$parent."')
            AND chief_emp_code != ''
      			AND (start_date <= '".$start_date."' OR start_date <= '".$end_date."')
      			GROUP BY emp_code
          ");

          if(empty($emp)) {
            $have = false;
            //  สิ้นสุดสาย emp
          }// end if
          else if (!empty($emp)){
            $place_parent = $place_parent.$parent; // เก็บ emp_code ก่อนหน้าไว้ใน $place_parent
            $parent = "";

            foreach ($emp as $o) {
              $parent = $parent.$o->emp_code.",";
              // ข้อมูล emp_code ล่าสุดที่ได้จาก Query
            }
          }
        }// end else

        $place_parent = $place_parent.$parent; // เก็บ emp_code ล่าสุดที่หา emp_code ต่อไปไม่เจอ

    		// ลูกน้องที่ต้องการจะต้องมี start_date ล่าสุด ตาม parameter date
    		$MaxDateEmp = "
    			SELECT emp_code, max(start_date) as max_date
    			FROM employee_snapshot
    			WHERE FIND_IN_SET(emp_code,'".$place_parent."')
    			AND emp_snapshot_id != ".$emp_snapshot_id."
    			AND (start_date <= '".$start_date."' OR start_date <= '".$end_date."')
    			GROUP BY emp_code";

        // นำ emp_code ไปหา emp_snapshot_id (ไม่เอาตัวเอง) และเอาเฉพาะลูกน้องคนที่มีวันที่มากที่สุดตาม parameter date และเอาเฉพาะลูกน้องที่มี job_function.is_show_report = 1
        $AllempID = DB::select("
          SELECT em.emp_snapshot_id
          FROM employee_snapshot em
          INNER JOIN job_function jf ON em.job_function_id = jf.job_function_id
		      INNER JOIN (".$MaxDateEmp.") md ON md.emp_code = em.emp_code
			       AND md.max_date = em.start_date
          WHERE FIND_IN_SET(em.emp_code,'".$place_parent."')
          AND em.emp_snapshot_id != ".$emp_snapshot_id."
          AND jf.is_show_report = 1
        ");

        $Allemp = "";     // เก็บ emp_snapshot_id ในรูปแบบของ String
        foreach ($AllempID as $empID) {
          $Allemp = $Allemp.$empID->emp_snapshot_id.",";
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

      /*
      if (!isset($groups[$key_group])) { // group ตาม Information
        if (!isset($groups[$key_group][$key_type])) { // group ตาม questionaire_type
          if (!isset($groups[$key_group][$key_type][$key_job])) { // group ตาม job_function
            $groups[$key_group][$key_type][$key_job] = array(
              'sum_count_int' => $re->head_count_int
            );
          }else{
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] += $re->head_count_int;
          }
        }else { // group ตาม questionaire_type
          if (!isset($groups[$key_group][$key_type][$key_job])) { // group ตาม job_function
            $groups[$key_group][$key_type][$key_job] = array(
              'sum_count_int' => $re->head_count_int
            );
          }else{
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] += $re->head_count_int;
          }
        }
      }
      else { // group ตาม Information
        if (!isset($groups[$key_group][$key_type])) { // group ตาม questionaire_type
          if (!isset($groups[$key_group][$key_type][$key_job])) { // group ตาม job_function
            $groups[$key_group][$key_type][$key_job] = array(
              'sum_count_int' => $re->head_count_int
            );
          }else{
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] += $re->head_count_int;
          }
        }else { // group ตาม questionaire_type
          if (!isset($groups[$key_group][$key_type][$key_job])) { // group ตาม job_function
            $groups[$key_group][$key_type][$key_job] = array(
              'sum_count_int' => $re->head_count_int
            );
          }else{
            $groups[$key_group][$key_type][$key_job]['sum_count_int'] += $re->head_count_int;
          }
        }
      }
      */


      /* //search emp, position by head_count [sql by daris]
         $emp_position = "
          SELECT snap.emp_snapshot_id
          , CONCAT( snap.emp_first_name, ' ', snap.emp_last_name ) AS emp_name
          , po.position_id
          , po.position_code
          FROM head_count h
          INNER JOIN employee_snapshot snap ON (
            h.position_id = snap.position_id
            AND snap.start_date = (
              SELECT max( e.start_date )
              FROM employee_snapshot e
              WHERE e.position_id = snap.position_id
              AND e.start_date <= h.valid_date
            )
            AND snap.level_id = 4 )
          LEFT JOIN position po ON h.position_id = po.position_id
          WHERE h.valid_date BETWEEN '".$param_date_start."' AND '".$param_date_end."'
          GROUP BY snap.emp_snapshot_id
          , CONCAT( snap.emp_first_name, ' ', snap.emp_last_name )
          , po.position_id
          , po.position_code"; */


}
