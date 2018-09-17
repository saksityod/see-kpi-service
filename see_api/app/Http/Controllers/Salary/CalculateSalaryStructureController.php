<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

use Auth;
use DB;
use Exception;
use App\User;
use App\AppraisalPeriod;
use App\SalaryStructure;
use App\EmpResult;
use App\Employee;
use App\AppraisalGrade;
use App\AppraisalStructure;



class CalculateSalaryStructureController extends Controller
{
    public function __construct()
	{
	   $this->middleware('jwt.auth');
    }


    public function index(){
    }


    public function ParameterPeriod(){
        return response()->json( 
            AppraisalPeriod::select("period_id", "appraisal_year", "appraisal_period_desc")
                ->where("is_raise", 1)
                ->get()
        );
    }


    /**
     * 1. คำนวณเกรดให้กับ emp
     * 2. ทำการปรับเงินเดือน
     *      2.1 ค้นหา emp ที่ได้ Grade ที่ไม่มี Struc (Step-1)
     *          - ปรับเงินเดือน Raise Step
     *      2.2 ค้นหา emp ที่ได้ Grade ที่มี Struc ผูกอยู่ (Step-2, Step-3)
     *      2.3 ตรวจสอบผลผลการประเมิณของ item ที่อยู่ภายใต้ ข้อ 2.2 (เช็คที่ตาราง appraisal_item_result ที่ no_raise_value กับ actual_value)
     *          2.3.1 ไม่ผ่านการประเมิณเลยซัก Item เดียวที่อยู่ภายใต้ Struc
     *              - ไม่ปรับเงินเดือน
     *          2.3.2 ผ่านการประเมิณเพียงแค่บาง Item ที่อยู่ภายใต้ Struc
     *              - ไม่ปรับเงินเดือน
     *          2.3.3 ผ่านการประเมิณของทุก Item ที่อยู่ภายใต้ Struc 
     *              2.3.3.1 ตรวจสอบว่า appraisal_structure.is_no_raise_value (ต้องผ่านการพิจารณาหรือไม่)
     *                  2.3.3.1.1 กรณี is_no_raise_value = 0 (ไม่ต้องผ่านการพิจารณา)
     *                      - ปรับเงินเดือน Raise Step
     *                  2.3.3.1.2 กรณี is_no_raise_value = 1 (จำเป็นต้องผ่านการพิจารณา)
     *                      - บันทึก Grade, judgement_status(status = 1-รอการพิจารณา) ลงที่ emp_result
     * 3. ปรับเงินเดือน (สั่งงานจากหน้า Judgement)
     *      3.1 ดึง emp judgement_status = 2-พิจารณาแล้ว 
     *      3.2 ตรวจสอบผลการพิจารณา 
     *          3.2.1 ผ่านการพิจารณา 
     *              - ปรับเงินเดือน Raise Step
     *          3.2.2 ไม่ผ่านการพิจารณา
     *              - ไม่ปรับเงินเดือน
     * **การปรัปเงินเดือน update ข้อมูลที่ตาราง emp_result(judgement_status_id,raise_amount,new_s_amount), employee(step,s_amount)
     */
    public function SalaryRaise(Request $request)
    {

        // get period infomation //
        try {
			$periodInfo = AppraisalPeriod::FindOrFail($request->period_id);
		} catch(ModelNotFoundException $e) {
			return response()->json(["status"=>404, "data"=>"Salary Appraisal Period not found."]);
        }

        // 1. คำนวณเกรดให้กับ emp //
        $calgrade = $this->GradeCalculate($periodInfo);
        if ($calgrade->status == 404) {
            return response()->json(["status"=>$gradeResult->status, "data"=>$gradeResult->data]);
        }
        
        // 2. ทำการปรับเงินเดือน //
        // 2.1 ค้นหา emp ที่ได้ Grade ที่ไม่มี Struc (Step-1) และทำการปรับเงินเดือน //
        $raiseStep[] = $this->SalaryRaiseStep1($periodInfo);

        // 2.2 ค้นหา emp ที่ได้ Grade ที่มี Struc ผูกอยู่ (Step-2, Step-3) //
        $raiseStep[] = $this->SalaryRaiseStep2_3($periodInfo);


        
        return response()->json(["status"=>200, "data"=>$raiseStep]);
    }


    public function SalaryRieseJudgement(Request $request)
    {
        $empLog = [];
        foreach ($request->emp as $emp) {
            // Update เงินเดือนให้ emp //
            $empLog[] = $this->RaiseUp($request->periodInfo, $request->grade, $emp);
        }

        return response()->json($empLog); 
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
                    AND (er.judgement_status_id is null OR er.judgement_status_id = 0)
                    GROUP BY er.emp_id, er.org_id, er.position_id, er.level_id
                    HAVING status_all_end = 0
                )grade
                    ON '{$periodInfo->period_id}' = empr.period_id
                    AND grade.emp_id = empr.emp_id
                    AND grade.org_id = empr.org_id
                    AND grade.position_id = empr.position_id
                    AND grade.level_id = empr.level_id
                LEFT OUTER JOIN appraisal_grade ag ON ag.grade_id = grade.result_grade
                SET 
                    empr.salary_grade_id = grade.result_grade,
                    empr.grade = ag.grade,
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
        // ดึงข้อมูล grade ที่ขึ้นเงินเดือน step1(ไม่มี Struc และไม่มี judgement) เพื่อหา raise step //
        $gradeStep1 = DB::select("
            SELECT grade_id, appraisal_level_id, grade, salary_raise_step
            FROM appraisal_grade
            WHERE (structure_id is null or structure_id = 0)
            AND is_judgement = 0");

        $empLog = [];
        foreach ($gradeStep1 as $grade) {
            // ดึงข้อมูลของ emp ที่ถูกคิด grade ว่าอยู่ที่ level ไหน และปัจจุบันมี step และ salary เท่าไหร่ //
            $empGrade = DB::select("
                SELECT er.emp_result_id, emp.emp_id, emp.level_id,
                    IFNULL(emp.step,0.00) emp_cur_step, IFNULL(emp.s_amount,0.00) emp_cur_salary
                FROM emp_result er
                INNER JOIN employee emp 
                    ON emp.emp_id = er.emp_id 
                    AND er.org_id = er.org_id
                    AND er.position_id = er.position_id
                    AND er.level_id = er.level_id
                WHERE (er.judgement_status_id IS NULL OR er.judgement_status_id = 0)
                AND er.period_id = '{$periodInfo->period_id}'
                AND er.salary_grade_id = '{$grade->grade_id}'
                AND er.level_id = '{$grade->appraisal_level_id}'");

            foreach ($empGrade as $emp){
                // Update เงินเดือนให้ emp //
                $empLog[] = $this->RaiseUp($periodInfo, $grade, $emp);
            }
        }

        return $empLog;
    }


    private function SalaryRaiseStep2_3($periodInfo)
    {
        // ดึงข้อมูล grade ที่ขึ้นเงินเดือน step2(Structure_id ไม่เท่ากับค่าว่าง) เพื่อนำไปหา Item ที่ต้องการประเมิณ //
        $gradeStep = AppraisalGrade::select("grade_id", "appraisal_level_id", "grade", "salary_raise_step", "structure_id", "is_judgement")
            ->whereNotNull("structure_id")
            ->where("structure_id", "!=", "0")
            ->get();

        $empLog = [];
        foreach ($gradeStep as $grade) {            
            // ดึงข้อมูลของ emp ที่ถูกคิด grade ว่าอยู่ที่ level ไหน และปัจจุบันมี step และ salary เท่าไหร่ //
            $empGrade = DB::select("
                SELECT er.emp_result_id, emp.emp_id, emp.level_id,
                    IFNULL(emp.step,0.00) emp_cur_step, IFNULL(emp.s_amount,0.00) emp_cur_salary
                FROM emp_result er
                INNER JOIN employee emp 
                    ON emp.emp_id = er.emp_id 
                    AND er.org_id = er.org_id
                    AND er.position_id = er.position_id
                    AND er.level_id = er.level_id
                WHERE (er.judgement_status_id IS NULL OR er.judgement_status_id = 0)
                AND er.period_id = '{$periodInfo->period_id}'
                AND er.salary_grade_id = '{$grade->grade_id}'
                AND er.level_id = '{$grade->appraisal_level_id}'
            ");

            foreach ($empGrade as $emp){
                // 2.3 ตรวจสอบผลผลการประเมิณของ item ที่อยู่ภายใต้ ข้อ 2.2 (เช็คที่ตาราง appraisal_item_result ที่ no_raise_value กับ actual_value) //
                $appraisalItemResult = DB::select("
                    SELECT air.item_result_id, air.emp_result_id, air.item_id, 
                        air.no_raise_value, air.actual_value
                    FROM appraisal_item_result air
                    INNER JOIN appraisal_item ai ON ai.item_id = air.item_id
                    INNER JOIN appraisal_structure stc ON stc.structure_id = ai.structure_id
                    WHERE emp_result_id = '{$emp->emp_result_id}'
                    AND stc.structure_id = '{$grade->structure_id}'
                ");
                $appraisalItemResult = collect($appraisalItemResult);
                $itemInvalid = $appraisalItemResult->filter(function ($item){
                    return $item->actual_value > $item->no_raise_value;
                });

                if( ! $itemInvalid->isEmpty()){
                    // 2.3.1 ไม่ผ่านการประเมิณเลยซัก Item เดียวที่อยู่ภายใต้ Struc - ไม่ปรับเงินเดือน //
                    // 2.3.2 ผ่านการประเมิณเพียงแค่บาง Item ที่อยู่ภายใต้ Struc - ไม่ปรับเงินเดือน //
                    // Do not thing //
                } else { 
                    // 2.3.3 ผ่านการประเมิณของทุก Item ที่อยู่ภายใต้ Struc //
                    // 2.3.3.1 ตรวจสอบว่า appraisal_structure.is_no_raise_value (ต้องผ่านการพิจารณาหรือไม่) //
                    $appraisalStructure = AppraisalStructure::find($grade->structure_id);
                    if($appraisalStructure->is_no_raise_value == 0){
                        // 2.3.3.1.1 กรณี is_no_raise_value = 0 (ไม่ต้องผ่านการพิจารณา) - ปรับเงินเดือน //
                        $empLog[] = $this->RaiseUp($periodInfo, $grade, $emp);
                    } else {
                        // 2.3.3.1.2 กรณี is_no_raise_value = 1 (จำเป็นต้องผ่านการพิจารณา) //
                        // บันทึก Grade, judgement_status(status = 1-รอการพิจารณา) ลงที่ emp_result //
                        EmpResult::where("emp_result_id", $emp->emp_result_id)->update(["judgement_status_id" => 1]);
                        $empLog[] = [
                            "emp_id"=>$emp->emp_id, "level_id"=>$emp->level_id,
                            "cur_step"=>$emp->emp_cur_step, "cur_salary"=>$emp->emp_cur_salary,
                            "grade"=>$grade->grade, "salary_raise_step"=>$grade->salary_raise_step,
                            "new_step"=>0, "new_salary"=>0, "raise_amount"=>0,
                            "judgement_status_id"=>1
                        ];
                    }
                }
            }
        }
        return $empLog;
    }


    private function RaiseUp($periodInfo, $grade, $emp)
    {
        // ตรวจสอบ และ set ค่า ให้กับ emp ที่ไม่มี step และ salary (ตั้งค่าให้มี step, salary เป็นค่าน้อยที่สุดของ level นั้นๆ ) //
        if($emp->emp_cur_step == 0){
            $firstStepLevel = DB::select("
                SELECT  ss.step, ss.s_amount
                FROM salary_structure ss
                WHERE ss.appraisal_year = '{$periodInfo->appraisal_year}'
                AND ss.level_id = '{$emp->level_id}'
                AND ss.step = (
                    SELECT MIN(ms.step) 
                    FROM salary_structure ms
                    WHERE ms.appraisal_year = ss.appraisal_year
                    AND ms.level_id = ss.level_id
                )");
            $emp->emp_cur_step = (float) $firstStepLevel[0]->step;
            $emp->emp_cur_salary = (float) $firstStepLevel[0]->s_amount;
        }

        // ดึงข้อมูลจาก salary struc ของปีที่อยู่ใน period และ level ของ emp ที่อยู่ใน loop ปัจจุบัน //           
        $salaryStruc = SalaryStructure::where("appraisal_year", $periodInfo->appraisal_year)
            ->where("level_id", $emp->level_id)
            ->get();

        // ตรวจสอบและกำหนดค่าให้กับ emp ที่มี next step เกินกว่าที่กำหนดใน level นั้นๆ //
        $nextStep = ((float)$emp->emp_cur_step) + ((float)$grade->salary_raise_step);
        $levelMaxStep = $salaryStruc->max("step");
        if($nextStep > $levelMaxStep){
            $salaryStruc = $salaryStruc->where("step", $levelMaxStep)->first();
        } else {
            $salaryStruc = $salaryStruc->where("step", $nextStep)->first();
        }

        // ตรวจสอบเงินเดือนขั้นต่ำ (minimum_wage_amount) //
        if($salaryStruc->minimum_wage_amount > $salaryStruc->s_amount){
            $salaryStruc->s_amount = $salaryStruc->minimum_wage_amount;
        }
        
        // Update ข้อมูลเงินเดือนที่ตาราง emp_result //
        EmpResult::where("emp_result_id", $emp->emp_result_id)
            ->update([
                "judgement_status_id" => 3, 
                "raise_amount" => ((float)$salaryStruc->s_amount) - ((float)$emp->emp_cur_salary),
                "new_s_amount" => $salaryStruc->s_amount
            ]);
        
        // Update ข้อมูลเงินเดือนที่ตาราง emp //
        Employee::where("emp_id", $emp->emp_id)
            ->update([
                "step" => $salaryStruc->step,
                "s_amount" => $salaryStruc->s_amount
            ]);

        // Log //
        return [
            "emp_id"=>$emp->emp_id, "level_id"=>$emp->level_id, 
            "cur_step"=>$emp->emp_cur_step, "cur_salary"=>$emp->emp_cur_salary,
            "grade"=>$grade->grade, "salary_raise_step"=>$grade->salary_raise_step,
            "new_step"=>$salaryStruc->step, "new_salary"=>$salaryStruc->s_amount, 
            "raise_amount"=>((float)$salaryStruc->s_amount) - ((float)$emp->emp_cur_salary),
            "judgement_status_id" => 3
        ];
    }
}