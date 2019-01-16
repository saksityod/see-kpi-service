<?php

namespace App\Http\Controllers\Salary;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Auth;
use DB;
use Exception;

use App\AppraisalPeriod;
use App\AppraisalStage;
use App\SystemConfiguration;

use App\Http\Requests;
use App\Http\Controllers\Controller;


class CalculateSalaryFormController extends Controller
{

    public function __construct()
	{
	   $this->middleware('jwt.auth');
    }


    /**
     * Salary calculator 
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function SalaryCalculator(Request $request)
    {
        $authId = Auth::id();
        $curDateTime = date('Y-m-d H:i:s');

        // get period infomation
        try {
			$periodInfo = AppraisalPeriod::FindOrFail($request->period_id);
		} catch(ModelNotFoundException $e) {
			return response()->json(["status"=>404, "data"=>"Salary Appraisal Period not found."]);
        }

        // คำนวณเกรดให้กับ emp
        $calgrade = $this->GradeCalculateWithAppraisalForm($periodInfo);
        if ($calgrade->status == 404) {
            return response()->json(["status"=>$calgrade->status, "data"=>$calgrade->data]);
        }

        // get Raise Type : 1="Fix Amount", 2="Percentage", 3="Salary Structure Table"
        $raiseType = SystemConfiguration::first()->raise_type;
        // return response()->json(["status"=>404, "data"=>$raiseType]);
        // update salary by Raise Type
        if ($raiseType == 1) {
            try{
                $salaryUpdate = DB::update("
                    UPDATE emp_result er 
                    INNER JOIN (
                        SELECT ser.emp_result_id, af.is_raise, af.is_mpi,
                            emp.emp_id, ser.salary_grade_id, emp.mpi_amount,
                            TO_BASE64(FROM_BASE64(emp.s_amount) + ag.salary_raise_amount) AS new_s_amount,
                            ag.salary_raise_amount AS raise_amount,
                            0 AS raise_pqpi_amount,
                            0 AS new_pqpi_amount
                        FROM employee emp
                        INNER JOIN emp_result ser ON ser.emp_id = emp.emp_id
                        INNER JOIN appraisal_grade ag ON ag.grade_id = ser.salary_grade_id
                        INNER JOIN appraisal_form af ON af.appraisal_form_id = ser.appraisal_form_id
                        WHERE emp.is_active = 1
                        AND ag.is_active = 1
                        AND ser.period_id = '{$request->period_id}'
                    )emp ON emp.emp_result_id = er.emp_result_id
                    SET
                        er.new_s_amount = IF(emp.is_raise=1,emp.new_s_amount,er.new_s_amount),
                        er.raise_amount = IF(emp.is_raise=1, emp.raise_amount, er.raise_amount),
                        er.mpi_amount = IF(emp.is_mpi=1, emp.mpi_amount, er.mpi_amount),
                        er.adjust_raise_s_amount = IF(emp.is_raise=1,emp.raise_amount,er.raise_amount),
                        er.adjust_raise_pqpi_amount = 0,
                        er.adjust_new_s_amount = IF(emp.is_raise=1,emp.new_s_amount,er.new_s_amount),
                        er.adjust_new_pqpi_amount = er.pqpi_amount,
                        er.updated_by = '{$authId}',
                        er.updated_dttm = '{$curDateTime}'
                ");
            } catch(QueryException $qx) {
                return response()->json(["status"=>404, "data"=>$qx->getMessage()]);
            }
        } elseif($raiseType == 2) {
            try {
                $salaryUpdate = DB::update("
                    UPDATE emp_result er 
                    INNER JOIN (
                        SELECT ser.emp_result_id, af.is_raise, af.is_mpi,
                            emp.emp_id, ser.salary_grade_id, emp.mpi_amount,
                            TO_BASE64(FROM_BASE64(emp.s_amount) + (FROM_BASE64(emp.s_amount) * (ag.salary_raise_percent / 100))) AS new_s_amount,
                            (FROM_BASE64(emp.s_amount) * (ag.salary_raise_percent / 100)) AS raise_amount,
                            0 AS raise_pqpi_amount,
                            0 AS new_pqpi_amount
                        FROM employee emp
                        INNER JOIN emp_result ser ON ser.emp_id = emp.emp_id
                        INNER JOIN appraisal_grade ag ON ag.grade_id = ser.salary_grade_id
                        INNER JOIN appraisal_form af ON af.appraisal_form_id = ser.appraisal_form_id
                        WHERE emp.is_active = 1
                        AND ag.is_active = 1
                        AND ser.period_id = '{$request->period_id}'
                    )emp ON emp.emp_result_id = er.emp_result_id
                    SET
                        er.new_s_amount = IF(emp.is_raise=1,emp.new_s_amount,er.new_s_amount),
                        er.raise_amount = IF(emp.is_raise=1,emp.raise_amount,er.raise_amount),
                        er.mpi_amount = IF(emp.is_mpi=1,emp.mpi_amount,er.mpi_amount),
                        er.adjust_raise_s_amount = IF(emp.is_raise=1,emp.raise_amount,er.raise_amount),
                        er.adjust_raise_pqpi_amount = 0,
                        er.adjust_new_s_amount = IF(emp.is_raise=1,emp.new_s_amount,er.new_s_amount),
                        er.adjust_new_pqpi_amount = er.pqpi_amount,
                        er.updated_by = '{$authId}',
                        er.updated_dttm = '{$curDateTime}'
                ");
            } catch (QueryException $qx) {
                return response()->json(["status"=>404, "data"=>$qx->getMessage()]);
            }
        }else {
            return response()->json(["status"=>404, "data"=>"The function is not working in Raise Type Salary Structure Table({$raiseType})"]);
        }

        return response()->json(["status"=>200, "data"=>"Calculate salary successful ({$salaryUpdate} row)."]);
    }


    /**
     * Calculate grades for employees.
     * 
     * @param  \App\AppraisalPeriod  $periodInfo
     * @return object
     */
    private function GradeCalculateWithAppraisalForm($periodInfo)
    {
        $authId = Auth::id();
        $curDateTime = date('Y-m-d H:i:s');
        $gradeResult = array();

        $appraisalStage = AppraisalStage::where('grade_calculation_flag', 1)->first();

        try {
            $gradeEmp = DB::update("
                UPDATE emp_result empr
                INNER JOIN (
                    SELECT er.appraisal_form_id, er.emp_id, er.org_id, er.position_id, er.level_id,
                        (
                            SELECT erj.adjust_result_score
                            FROM emp_result_judgement erj
                            WHERE erj.emp_result_id = er.emp_result_id
                            ORDER BY erj.created_dttm DESC
                            LIMIT 1
                        ) AS result_score,
                        (
                            SELECT ag.grade_id
                            FROM appraisal_grade ag
                            WHERE ag.appraisal_form_id = er.appraisal_form_id
                            AND ag.appraisal_level_id = er.level_id
                            AND result_score BETWEEN ag.begin_score AND ag.end_score
                            LIMIT 1
                        ) result_grade,
                        MAX(IF(er.stage_id={$appraisalStage->stage_id},1,0)) can_be_calculated
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
                    GROUP BY er.appraisal_form_id, er.emp_id, er.org_id, er.position_id, er.level_id
                    HAVING can_be_calculated = 1
                )grade
                    ON '{$periodInfo->period_id}' = empr.period_id
                    AND grade.appraisal_form_id = empr.appraisal_form_id
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

}