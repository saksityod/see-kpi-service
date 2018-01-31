<?php

namespace App\Http\Controllers;

use App\KPIType;
use App\EmpResult;
use App\AppraisalItemResult;
use App\EmpResultStage;

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

class ImportAssignmentController extends Controller
{

  public function __construct(){
	   $this->middleware('jwt.auth');
	}
	
	
	/**
   * Get Level list filter by Org.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( emp_code )
   * @return \Illuminate\Http\Response
   */
  public function org_level_list(Request $request){
    // Get user level
    $userlevelId = 0; $userlevelAllEmp = 0;
    $userlevelDb = DB::select("
      SELECT al.level_id, al.appraisal_level_name, al.is_all_employee
      FROM employee emp
      INNER JOIN org ON org.org_id = emp.org_id
      INNER JOIN appraisal_level al ON al.level_id = org.level_id
      WHERE emp_code = '{$request->emp_code}'
      AND al.is_org = 1
      AND al.is_active = 1
      AND emp.is_active = 1
      AND org.is_active = 1
      LIMIT 1");
    foreach ($userlevelDb as $value) {
      $userlevelId = $value->level_id;
      $userlevelAllEmp = $value->is_all_employee;
    }

    $resultQryStr = "";
    if ($userlevelAllEmp == '1') {
      $result = DB::select("
        SELECT level_id, appraisal_level_name
        FROM appraisal_level
        WHERE is_active = 1");
    } else {
      $result = DB::select("
      SELECT level_id, appraisal_level_name
      FROM appraisal_level
      WHERE is_active = 1
      AND level_id = {$userlevelId}
      OR level_id in(
      	SELECT
      		@id := (
      			SELECT level_id
      			FROM appraisal_level
      			WHERE parent_id = @id
      		) AS level_id
      	FROM(
      		SELECT @id := {$userlevelId}
      	) cur_id
      	STRAIGHT_JOIN appraisal_level
      	WHERE @id IS NOT NULL
      )");
    }

		return response()->json($result);
	}


	
  /**
   * Get Level list filter by Employee.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( appraisal_type_id )
   * @return \Illuminate\Http\Response
   */
  public function emp_level_list(Request $request){
      $result = DB::select("
        SELECT level_id, appraisal_level_name
        FROM appraisal_level
        WHERE is_active = 1
        AND is_individual = 1
        ORDER BY level_id
      ");

		return response()->json($result);
	}


	
  /**
   * Get Org list filter by Entity Type and Level.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request   $request( appraisal_type_id, level_id[] )
   * @return \Illuminate\Http\Response
   */
  public function org_list(Request $request){
    $levelStr = (empty($request->level_id)) ? "''" : "'".implode("','", $request->level_id)."'" ;
    if ($request->appraisal_type_id == "1") {
      $orgs = DB::select("
  			SELECT org_id, org_name
  			FROM org
  			WHERE is_active = 1
  			AND level_id IN({$levelStr})
  			ORDER BY org_id
  		");
    } else if($request->appraisal_type_id == "2") {
      $orgs = DB::select("
        SELECT emp.org_id, org.org_name
        FROM employee emp, org
        WHERE emp.org_id = org.org_id
        GROUP BY emp.org_id
        ORDER BY emp.org_id
      ");
    } else {
      $orgs = [];
    }

		return response()->json($orgs);
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
     $positionStr = (empty($request->position_id)) ? " " : "AND post.position_id = '{$request->position_id}'" ;

     $items = DB::select("
      SELECT distinct ai.structure_id, strc.structure_name, ai.item_id, ai.item_name, strc.form_id
      FROM appraisal_item ai
      INNER JOIN appraisal_structure strc ON strc.structure_id = ai.structure_id
      INNER JOIN appraisal_item_level vel ON vel.item_id = ai.item_id
      INNER JOIN appraisal_item_org iorg ON iorg.item_id = ai.item_id
      INNER JOIN appraisal_item_position post ON post.item_id = ai.item_id
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
    * Assignment export to Excel.
    *
    * @author P.Wirun (GJ)
    * @param  \Illuminate\Http\Request
    *         $request (appraisal_type_id, position_id, emp_id, period_id, appraisal_year,
    *                   frequency_id, org_id[], appraisal_item_id[], appraisal_level_id[])
    * @return \Illuminate\Http\Response
    */
    public function export_template(Request $request){

      // Set file name and directory.
      $extension = "xlsx";
      $fileName = "import_assignment_".date('Ymd His');;  //yyyymmdd hhmmss
      //$outpath = public_path()."/export_file";
      //File::isDirectory($outpath) or File::makeDirectory($outpath, 0777, true, true);

      // Set Input parameter for test
      // $appraisal_type_id = "1";
      // $appraisal_level_id = "'".implode("','", ["8","9", "10"] )."'";
      // $org_id = "'".implode("','", ["294","295"] )."'";
      // $appraisal_item_id = "'".implode("','", ["47","48","49","50","51","52","53","54","55"] )."'";
      // $position_id = "";
      // $emp_id = "";
      // $period_id = "";
      // $appraisal_year = "2017";
      // $frequency_id = "2";

      // Set Input parameter
      $appraisal_type_id = $request->appraisal_type_id;
      $appraisal_level_id = (empty($request->appraisal_level_id)) ? "''" : "'".implode("','", $request->appraisal_level_id)."'" ;
      $org_id = (empty($request->org_id)) ? "''" : "'".implode("','", $request->org_id)."'" ;
      $appraisal_item_id = (empty($request->appraisal_item_id)) ? "''" : "'".implode("','", $request->appraisal_item_id)."'" ;
      $position_id = $request->position_id;
      $emp_id = $request->emp_id;
      $period_id = $request->period_id;
      $appraisal_year = $request->appraisal_year;
      $frequency_id = $request->frequency_id;

      // Set parameter string in where clause
      $positionStr = (empty($position_id)) ? "" : " emp.position_id = '{$position_id}'";
      $empStr = (empty($emp_id)) ? "" : " emp.emp_id = '{$emp_id}'";
      if (!empty($positionStr) && !empty($empStr)) {
        $empContStr = " or ";
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
        	typ.appraisal_type_id, typ.appraisal_type_name, typ.stage_id, typ.status,
        	emp.level_id, emp.appraisal_level_name level_name, emp.org_id,
        	emp.org_name, emp.position_id, emp.position_name, emp.chief_emp_id,
        	emp.chief_emp_code, emp.chief_emp_name, emp.emp_id, emp.emp_code,
        	emp.emp_name, item.item_id appraisal_item_id,
        	item.item_name appraisal_item_name, item.uom_name,
        	item.structure_name, item.form_id, item.nof_target_score
        FROM(
        	SELECT
        		emp.level_id, vel.appraisal_level_name,
        		emp.org_id, org.org_name,
        		emp.position_id, pos.position_name,
        		emp.emp_id, emp.emp_code, emp.emp_name,
        		chf.emp_id chief_emp_id, chf.emp_code chief_emp_code, chf.emp_name chief_emp_name
        	FROM employee emp
        	LEFT OUTER JOIN employee chf ON chf.emp_code = emp.chief_emp_code
        	INNER JOIN appraisal_level vel ON vel.level_id = emp.level_id
        	INNER JOIN org ON org.org_id = emp.org_id
        	INNER JOIN position pos ON pos.position_id = emp.position_id
        	WHERE emp.is_active = 1
        	AND vel.is_active = 1
        	AND org.is_active = 1
        	AND pos.is_active = 1
        	AND emp.level_id IN({$appraisal_level_id})
        	AND emp.org_id IN({$org_id})
        	AND (".$positionStr.$empContStr.$empStr.")
        )emp
        LEFT JOIN (
        	SELECT ail.level_id, aio.org_id,
        		itm.item_id, itm.item_name, uom.uom_name,
        		strc.structure_name, strc.form_id, strc.nof_target_score
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

      // Generate Excel from query result.
      // Return 404, If not found data.
      if(!empty($items)){
        // Set grouped to create sheets.
        $itemList = [];
        foreach($items as $value) {
          if ($value->form_id == "1") {
            $itemList[$value->structure_name][] = [
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
              "target" => "",
              "weight" => "",
              "range0" => "",
              "range1" => "",
              "range2" => "",
              "range3" => "",
              "range4" => "",
              "range5" => ""
            ];

          } else if($value->form_id == "2") {
            $itemList[$value->structure_name][] = [
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
              "target" => "",
              "weight" => ""
            ];
          }else if($value->form_id == "3"){
            $itemList[$value->structure_name][] = [
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
              "max_value" => "",
              "score_per_unit" => "",
              "value_get_zero" => ""
            ];
          }
        }

        Excel::create($fileName, function($excel) use ($itemList) {

          foreach ($itemList as $key => $group) {

            $excel->sheet($key, function($sheet) use ($key, $itemList){
              $sheet->fromArray($itemList[$key]);
            });

          }

        })->download($extension); //->store($extension, $outpath);
        //return response()->download($outpath."/".$fileName.".".$extension, $fileName.".".$extension);
      }else{
        return response()->json(['status' => 404, 'data' => 'Assignment Item Result not found.']);
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

  		foreach ($request->file() as $f) {
        DB::beginTransaction();

        $items = Excel::load($f, function($reader){})->get();

        // Fetch value by sheets
  			foreach ($items as $key => $sheets) {

          // Fetch value by rows
          foreach ($sheets as $key => $row) {

            // Validate data from excel file
            $validator = Validator::make($row->all(), [
               "period_id" => "required|numeric",
               "year" => "numeric",
               "start_date" => "date",
               "end_date" => "date",
               "appraisal_type_id" => "required|numeric",
               //"appraisal_type_name" => "",
               "stage_id" => "required|numeric",
               "status" => "required",
               "level_id" => "required|numeric",
               //"level_name" => "",
               "org_id" => "required|numeric",
               //"org_name" => "",
               "position_id" => "numeric",
               //"position_name" => "",
               "chief_emp_id" => "numeric",
               //"chief_emp_code" => "",
               //"chief_emp_name" => "",
               "emp_id" => "numeric",
               //"emp_code" => "",
               //"emp_name" => "",
               "appraisal_item_id" => "required|numeric",
               "appraisal_item_name" => "required",
            ]);

            if ($validator->fails()) {
        			$errors[] = [
                "period_id"=>$row->period_id, "appraisal_type_id"=>$row->appraisal_type_id,
                "level_id"=>$row->level_id, "org_id"=>$row->org_id, "emp_id"=>$row->emp_id,
                "data" => $validator->errors()
              ];
        		} else {

              // Insert/Update @emp_result
              $existEmpResultId = 0;
              $currentEmpResultId = 0;
              $empResultExist = DB::select("
                SELECT emp_result_id
                FROM emp_result
                WHERE period_id = ?
                AND appraisal_type_id = ?
                AND level_id = ?
                AND org_id = ?
                AND emp_id = ?
                LIMIT 1
              ", array($row->period_id, $row->appraisal_type_id, $row->level_id, $row->org_id, $row->emp_id));
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
                // --------------------------------------------------- //
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
    							$empResult->save();
                  $currentEmpResultId = $empResult->emp_result_id;
    						} catch (Exception $e) {
    							$errors[] = ["table_name"=>"appraisal_item_result", "period_id"=>$row->period_id, "appraisal_type_id"=>$row->appraisal_type_id,
                  "level_id"=>$row->level_id, "org_id"=>$row->org_id, "emp_id"=>$row->emp_id,
                  "errors" => substr($e,0,254)];
    						}
              } else {
                //---- Update @emp_result
                $updateStatus = EmpResult::where(
                  "emp_result_id", $existEmpResultId
                )->update([
                  "position_id" => $row->position_id,
                  "chief_emp_id" => $row->chief_emp_id,
                  "result_threshold_group_id" => $thresholdGroupId,
                  "stage_id" => $row->stage_id,
                  "status" => $row->status,
                  "updated_by" => "Emp Result Update",
                  "updated_dttm" => date("Y-m-d H:i:s")
                ]);
                $currentEmpResultId = $existEmpResultId;
              }


              // Insert/Update @appraisal_item_result
              $existItemResultId = 0;
              $itemResultExist = DB::select("
                SELECT item_result_id
                FROM appraisal_item_result
                WHERE emp_result_id = ?
                AND item_id = ?
                AND period_id = ?
                AND level_id = ?
                AND org_id = ?
                LIMIT 1
              ", array($currentEmpResultId, $row->appraisal_item_id, $row->period_id, $row->level_id, $row->org_id));
              foreach ($itemResultExist as $value) {
                $existItemResultId = $value->item_result_id;
              }

              //-- Checking if record exists in emp_result.
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
                //$appraisalItemResult->kpi_type_id = $row->???;
                $appraisalItemResult->score0 = $row->range0;
                $appraisalItemResult->score1 = $row->range1;
                $appraisalItemResult->score2 = $row->range2;
                $appraisalItemResult->score3 = $row->range3;
                $appraisalItemResult->score4 = $row->range4;
                $appraisalItemResult->score5 = $row->range5;
                $appraisalItemResult->target_value = $row->target;
                //$appraisalItemResult->forecast_value = $row->???;
                //$appraisalItemResult->actual_value = $row->???;
                //$appraisalItemResult->percent_achievement = $row->???;
                $appraisalItemResult->max_value = $row->max_value;
                //$appraisalItemResult->deduct_score_unit = $row->???;
                //$appraisalItemResult->over_value = $row->???;
                //$appraisalItemResult->score = $row->???;
                $appraisalItemResult->threshold_group_id = $thresholdGroupId;
                $appraisalItemResult->weight_percent = "0";
                $appraisalItemResult->weigh_score = "0";
                //$appraisalItemResult->structure_weight_percent = $row-???;
                $appraisalItemResult->created_by = Auth::id();
                $appraisalItemResult->updated_by = Auth::id();
                try {
    							$appraisalItemResult->save();
    						} catch (Exception $e) {
    							$errors[] = ["table_name"=>"appraisal_item_result", "emp_result_id"=>$currentEmpResultId, "item_id"=>$row->appraisal_item_id,
                  "period_id"=>$row->period_id, "level_id"=>$row->level_id, "org_id"=>$row->org_id,
                  "errors" => substr($e,0,254)];
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
                  //"kpi_type_id" => $row->???,
                  "score0" => $row->range0,
                  "score1" => $row->range1,
                  "score2" => $row->range2,
                  "score3" => $row->range3,
                  "score4" => $row->range4,
                  "score5" => $row->range5,
                  "target_value" => $row->target,
                  //"forecast_value" => $row->???,
                  //"actual_value" => $row->???,
                  //"percent_achievement" => $row->???,
                  "max_value" => $row->max_value,
                  //"deduct_score_unit" => $row->???,
                  //"over_value" => $row->???,
                  //"score" => $row->???,
                  "threshold_group_id" => $thresholdGroupId,
                  "weight_percent" => "0",
                  "weigh_score" => "0",
                  //"structure_weight_percent" => $row-???,
                  "updated_by" => Auth::id(),
                  "updated_dttm" => date("Y-m-d H:i:s")
                ]);
              }

            } // End validate
          }// End fetch value by rows
        }// End fetch value by sheets


        // Insert/Update @emp_result_stage
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
              $errors[] = ["table_name"=>"emp_result_stage",
              "emp_result_id"=>$value->emp_result_id,
              "errors" => substr($e,0,254)];
            }

          } else {
            //---- Update @emp_result_stage
            EmpResultStage::where(
              'emp_result_id', $value->emp_result_id
            )->update([
              "stage_id" => $value->stage_id,
              "updated_by" => Auth::id(),
              "updated_dttm" => date("Y-m-d H:i:s")
            ]);
          }
        }


        if (empty($errors)) {
          // All transaction good
          DB::commit();
        } else {
          // Something went wrong
          DB::rollback();
        }

  		}// End fetch by file

  		return response()->json(['status' => 200, 'errors' => $errors]);
  	}

}
