<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Auth;
use DB;
use Exception;

use App\User;
use App\AppraisalPeriod;



class CalculateSalaryStructureController extends Controller
{
    public function __construct()
	{
	   //$this->middleware('jwt.auth');
    }


    /**
     * 1. คำนวณเกรดให้กับ emp
     * 2. ทำการปรับเงินเดือน
     *      2.1 ค้นหา emp ที่ได้ Grade ที่ไม่มี Struc (Step-1)
     *          2.1.1 ปรับเงินเดือน Raise Step
     *      2.2 ค้นหา emp ที่ได้ Grade ที่มี Struc ผูกอยู่ (Step-2)
     *          2.2.1 ไม่ผ่านการประเมิณเลยซัก Item เดียวที่อยู่ภายใต้ Struc => ไม่ปรับเงินเดือน
     *          2.2.2 ผ่านการประเมิณเพียงแค่บาง Item ที่อยู่ภายใต้ Struc => ไม่ปรับเงินเดือน
     *          2.2.3 ผ่านการประเมิณของทุก Item ที่อยู่ภายใต้ Struc => ปรับเงินเดือน Raise Step
     * Step-3 : ได้ Grade ที่มี Struc ผูกอยู่ และผ่านการประเมิณของทุก Item ที่อยู่ภายใต้ Struc นั้น 
     *          -> บันทึก Grade, judgement status ลงที่ emp_result
     *          -> ปรับเงินเดือน (สั่งงานจากหน้า Judgement)
     *              -> ดึง emp ที่มีสถานะ 2-พิจารณาแล้ว 
     *                  -> ผ่านการพิจารณา => ปรับเงินเดือน Raise Step
     *                  -> ไม่ผ่านการพิจารณา => ไม่ปรับเงินเดือน
     */


    public function index(){
        
    }


    public function SalaryRaise(Request $request){

        // get period infomation //
        try {
			$periodInfo = AppraisalPeriod::FindOrFail(2);
		} catch(ModelNotFoundException $e) {
			return response()->json(["status"=>404, "data"=>"Salary Appraisal Period not found."]);
        }

        // 1. คำนวณเกรดให้กับ emp //
        $calgrade = $this->GradeCalculate($periodInfo);
        if ($calgrade->status == 404) {
            return response()->json(["status"=>$gradeResult->status, "data"=>$gradeResult->data]);
        }
        
        // 2. ทำการปรับเงินเดือน //
        // 2.1 ค้นหา emp ที่ได้ Grade ที่ไม่มี Struc (Step-1) //
        $raiseStep1 = $this->SalaryRaiseStep1($periodInfo);
        
        return response()->json($raiseStep1);
    }


    private function GradeCalculate($periodInfo)
    {
        $authId = Auth::id();
        $curDateTime = date('Y-m-d H:i:s');
        $gradeResult = array();

        try {
            $gradeEmp = DB::select("
                UPDATE emp_result empr
                INNER JOIN (
                    SELECT er.emp_id, er.org_id, er.position_id, er.level_id,
                        AVG(er.result_score) avg_result_score,
                        (
                            SELECT ag.grade_id
                            FROM appraisal_grade ag
                            WHERE ag.appraisal_level_id = er.level_id
                            AND AVG(er.result_score) BETWEEN ag.begin_score AND ag.end_score
                        ) result_grade,
                        SUM(IF(er.status='End',0,1)) status_all_end
                    FROM emp_result er
                    INNER JOIN employee emp 
                        ON emp.emp_id = er.emp_id 
                        AND er.org_id = er.org_id
                        AND er.position_id = er.position_id
                        AND er.level_id = er.level_id
                    WHERE er.period_id in(
                        SELECT period_id FROM appraisal_period
                        WHERE appraisal_frequency_id = {$periodInfo->appraisal_frequency_id}
                        AND end_date <= '{$periodInfo->end_date}'
                    )
                    AND er.appraisal_type_id = 2
                    AND er.result_score != 0
                    GROUP BY er.emp_id, er.org_id, er.position_id, er.level_id
                    HAVING status_all_end = 0
                )grade
                    ON '{$periodInfo->period_id}' = empr.period_id
                    AND grade.emp_id = empr.emp_id
                    AND grade.org_id = empr.org_id
                    AND grade.position_id = empr.position_id
                    AND grade.level_id = empr.level_id
                SET 
                    empr.salary_grade_id = result_grade,
                    empr.updated_by = '{$authId}',
                    empr.updated_dttm = '{$curDateTime}'
            ");

            $gradeResult = ["status"=>200, "data"=>"Grade Calculate Success"];

        } catch(QueryException $qx) {
            $gradeResult = ["status"=>404, "data"=>$qx->getMessage()];
        }
        return (object) $gradeResult;
    }


    private function SalaryRaiseStep1($periodInfo)
    {
        // get grade ที่ไม่มี Struc และไม่มี judgement
        $gradeStep1 = DB::select("
            SELECT grade_id, appraisal_level_id, grade, salary_raise_step
            FROM appraisal_grade
            WHERE (structure_id is null or structure_id = 0)
            AND is_judgement = 0
        ");

        foreach ($gradeStep1 as $grade) {
            // get employee information //
            $empGrade = DB::select("
                SELECT er.emp_result_id, emp.emp_id, emp.level_id, ap.period_id, ap.appraisal_year,
                    IFNULL(emp.step,0) emp_cur_step, IFNULL(emp.s_amount,0) emp_cur_salary
                FROM emp_result er
                INNER JOIN employee emp 
                    ON emp.emp_id = er.emp_id 
                    AND er.org_id = er.org_id
                    AND er.position_id = er.position_id
                    AND er.level_id = er.level_id
                INNER JOIN appraisal_period ap ON ap.period_id = '{$periodInfo->period_id}'
                WHERE er.period_id = '{$periodInfo->period_id}'
                AND er.salary_grade_id = '{$grade->grade_id}'
                AND er.level_id = '{$grade->appraisal_level_id}'
            ");

            foreach ($empGrade as $emp){

                
                return ["grade"=>$grade, "empGrade"=>$emp];
            }
        }

        //return ["grade"=>$grade, "empGrade"=>$empGrade];
    }


    // private function SalaryRaiseStep(){
    //     return false;
    // }


    // private function JudgementCalculate(){

    // }
}